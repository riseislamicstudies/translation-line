// writer-signup: public endpoint (deploy with --no-verify-jwt). Creates a writer account
// only when the request carries a valid, unexpired, unrevoked invite token.
// actions: "check" {token}  ->  {valid}
//          "avatar_upload_url" {token, mime}  ->  {path, token}   signed URL to upload the ORIGINAL photo (kept at full quality)
//          "signup" {token, first_name, last_name, phone, email, password, avatar, orig_path?}  ->  {status:"created"}
// avatar: small display copy, data URL (image/jpeg), resized by the browser, max ~300 KB. orig_path: the original from avatar_upload_url.
import { createClient } from "npm:@supabase/supabase-js@2";

const SUPABASE_URL = Deno.env.get("SUPABASE_URL")!;
const SERVICE_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!;
const ALLOWED_ORIGIN = Deno.env.get("ALLOWED_ORIGIN") ?? "*";

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const TOKEN_RE = /^[0-9a-f]{20,64}$/;
const PHONE_RE = /^[+0-9][0-9 \-()]{6,19}$/;

const corsHeaders = {
  "Access-Control-Allow-Origin": ALLOWED_ORIGIN,
  "Access-Control-Allow-Headers": "authorization, x-client-info, apikey, content-type",
  "Access-Control-Allow-Methods": "POST, OPTIONS",
};
const json = (b: unknown, status = 200) =>
  new Response(JSON.stringify(b), { status, headers: { ...corsHeaders, "Content-Type": "application/json" } });

// Persian/Arabic digits -> ASCII, so a phone typed on a Persian keyboard still validates
const toAscii = (s: string) =>
  s.replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0)).replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660));

Deno.serve(async (req) => {
  if (req.method === "OPTIONS") return new Response("ok", { headers: corsHeaders });
  if (req.method !== "POST") return json({ error: "method_not_allowed" }, 405);

  let body: Record<string, unknown>;
  try { body = await req.json(); } catch { return json({ error: "invalid_json" }, 400); }

  const token = String(body.token ?? "");
  if (!TOKEN_RE.test(token)) return json({ error: "invite_invalid" }, 400);

  const admin = createClient(SUPABASE_URL, SERVICE_KEY, { auth: { persistSession: false } });
  const action = String(body.action ?? "signup");

  if (action === "check") {
    const { data } = await admin.rpc("invite_is_valid", { p_token: token });
    return json({ valid: data === true });
  }
  if (action === "avatar_upload_url") {
    const { data: valid } = await admin.rpc("invite_is_valid", { p_token: token });
    if (valid !== true) return json({ error: "invite_invalid" }, 400);
    const ext = ({ "image/jpeg": "jpg", "image/png": "png", "image/webp": "webp" } as Record<string, string>)[String(body.mime ?? "")];
    if (!ext) return json({ error: "invalid_avatar" }, 400);
    const rnd = Array.from(crypto.getRandomValues(new Uint8Array(8))).map((b) => b.toString(16).padStart(2, "0")).join("");
    const path = `orig/pending-${rnd}.${ext}`;
    const { data, error } = await admin.storage.from("avatars").createSignedUploadUrl(path);
    if (error || !data) return json({ error: "upload_url_failed", detail: error?.message ?? "unknown" }, 500);
    return json({ path, token: data.token });
  }
  if (action !== "signup") return json({ error: "unknown_action" }, 400);

  const first = String(body.first_name ?? "").trim().slice(0, 60);
  const last = String(body.last_name ?? "").trim().slice(0, 60);
  const email = String(body.email ?? "").trim().toLowerCase();
  const password = String(body.password ?? "");
  const phone = toAscii(String(body.phone ?? "").trim());
  if (!first || !last) return json({ error: "name_required" }, 400);
  if (!EMAIL_RE.test(email) || email.length > 120) return json({ error: "invalid_email" }, 400);
  if (password.length < 8 || password.length > 72) return json({ error: "password_too_short", detail: "8 to 72 characters" }, 400);
  if (!PHONE_RE.test(phone)) return json({ error: "invalid_phone" }, 400);

  // avatar (required)
  if (!body.avatar) return json({ error: "avatar_required" }, 400);
  let avatarBytes: Uint8Array | null = null;
  let avatarType = "image/jpeg";
  const av = body.avatar ? String(body.avatar) : "";
  if (av) {
    const m = /^data:(image\/jpeg);base64,([A-Za-z0-9+/=]+)$/.exec(av);
    if (!m || av.length > 420_000) return json({ error: "invalid_avatar" }, 400);
    avatarType = m[1];
    avatarBytes = Uint8Array.from(atob(m[2]), (c) => c.charCodeAt(0));
  }

  // take one use of the invite (atomic); give it back on any failure below
  const { data: ok } = await admin.rpc("consume_invite", { p_token: token });
  if (ok !== true) return json({ error: "invite_invalid" }, 400);
  const giveBack = () => admin.rpc("release_invite", { p_token: token });

  const fullName = `${first} ${last}`;
  const { data, error } = await admin.auth.admin.createUser({
    email, password, email_confirm: true, user_metadata: { full_name: fullName },
  });
  if (error || !data.user) {
    await giveBack();
    const msg = error?.message ?? "unknown";
    if (/already|registered|exists/i.test(msg)) return json({ error: "email_taken" }, 400);
    return json({ error: "create_failed", detail: msg }, 400);
  }
  const uid = data.user.id;

  let avatarUrl: string | null = null;
  let origUrl: string | null = null;
  const bucket = admin.storage.from("avatars");
  if (avatarBytes) {
    const path = `thumb/${uid}.jpg`;
    const up = await bucket.upload(path, avatarBytes, { contentType: avatarType, upsert: true });
    if (!up.error) avatarUrl = bucket.getPublicUrl(path).data.publicUrl + `?v=${Date.now()}`;
  }
  // the original (full quality) was uploaded by the browser under a temporary name: give it the user's name
  const tmp = body.orig_path ? String(body.orig_path) : "";
  const tm = /^orig\/pending-[0-9a-f]{16}\.(jpg|png|webp)$/.exec(tmp);
  if (tm) {
    const dest = `orig/${uid}-${Date.now()}.${tm[1]}`;
    const mv = await bucket.move(tmp, dest);
    if (!mv.error) origUrl = bucket.getPublicUrl(dest).data.publicUrl;
  }

  const { error: upErr } = await admin.from("profiles")
    .update({ role: "writer", full_name: fullName, avatar_url: avatarUrl, avatar_original_url: origUrl }).eq("id", uid);
  if (upErr) {
    await admin.auth.admin.deleteUser(uid);   // no half-made account
    await giveBack();
    return json({ error: "profile_update_failed", detail: upErr.message }, 500);
  }
  const { data: inv } = await admin.from("invites").select("id").eq("token", token).maybeSingle();
  await admin.from("profile_contacts").upsert({
    id: uid, phone, email, first_name: first, last_name: last, invite_id: inv?.id ?? null,
  });
  return json({ status: "created" });
});
