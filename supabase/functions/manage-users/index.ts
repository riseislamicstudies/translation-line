// Edge Function: manage-users  (admin only)
//
// The browser cannot create auth users or read their emails (that needs the
// service-role key), so the Admin > Users page calls this function (admin only, except own profile photo).
//
// POST { action: "list" }
// POST { action: "create", email, password, full_name?, role? }
// POST { action: "set_password", user_id, password }
// POST { action: "update_email", user_id, email }
// POST { action: "disable" | "enable", user_id }   block / allow sign-in (a disabled user also gets role "none")
// POST { action: "avatar_upload_url", user_id, mime }  signed URL for uploading the ORIGINAL photo
// POST { action: "set_avatar", user_id, avatar, orig_path? }  avatar = small jpeg data URL (display copy); orig_path = the uploaded original; avatar "" removes both
// POST { action: "delete", user_id }               delete the account; refused when the user has work history (use disable)
//
// avatar_upload_url and set_avatar are also allowed for any signed-in user on their own account.
// Role changes and name edits do not need this function: an admin can update
// public.profiles directly (RLS allows it).

import { createClient } from "npm:@supabase/supabase-js@2";

const SUPABASE_URL = Deno.env.get("SUPABASE_URL")!;
const ANON_KEY = Deno.env.get("SUPABASE_ANON_KEY")!;
const SERVICE_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!;
const ALLOWED_ORIGIN = Deno.env.get("ALLOWED_ORIGIN") ?? "*";

const ROLES = ["none", "admin", "reviewer", "translator", "supervisor", "writer", "journal_writer", "news_editor"];
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const corsHeaders = {
  "Access-Control-Allow-Origin": ALLOWED_ORIGIN,
  "Access-Control-Allow-Headers": "authorization, x-client-info, apikey, content-type",
  "Access-Control-Allow-Methods": "POST, OPTIONS",
};

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { ...corsHeaders, "Content-Type": "application/json" },
  });
}

Deno.serve(async (req) => {
  if (req.method === "OPTIONS") return new Response("ok", { headers: corsHeaders });
  if (req.method !== "POST") return json({ error: "method_not_allowed" }, 405);

  const authHeader = req.headers.get("Authorization");
  if (!authHeader) return json({ error: "missing_authorization" }, 401);

  const userClient = createClient(SUPABASE_URL, ANON_KEY, {
    global: { headers: { Authorization: authHeader } },
    auth: { persistSession: false },
  });
  const { data: userData, error: userErr } = await userClient.auth.getUser();
  if (userErr || !userData.user) return json({ error: "invalid_token" }, 401);

  const { data: profile } = await userClient
    .from("profiles")
    .select("role")
    .eq("id", userData.user.id)
    .single();
  if (!profile || profile.role === "none") return json({ error: "forbidden" }, 403);

  const body = await req.json().catch(() => null);
  const action = body?.action;
  // everybody may change or remove their OWN profile photo; every other action is for admins only
  if (profile.role !== "admin") {
    const own = ["avatar_upload_url", "set_avatar"].includes(action) && String(body?.user_id ?? "") === userData.user.id;
    if (!own) return json({ error: "forbidden" }, 403);
  }
  const admin = createClient(SUPABASE_URL, SERVICE_KEY, { auth: { persistSession: false } });

  if (action === "list") {
    const { data, error } = await admin.auth.admin.listUsers({ page: 1, perPage: 200 });
    if (error) return json({ error: "list_failed", detail: error.message }, 500);
    return json({
      users: data.users.map((u) => ({
        id: u.id,
        email: u.email,
        last_sign_in_at: u.last_sign_in_at,
        created_at: u.created_at,
        banned_until: (u as { banned_until?: string | null }).banned_until ?? null,
      })),
    });
  }

  if (action === "create") {
    const email = String(body.email ?? "").trim().toLowerCase();
    const password = String(body.password ?? "");
    const fullName = String(body.full_name ?? "").trim();
    const role = String(body.role ?? "none");
    if (!EMAIL_RE.test(email)) return json({ error: "invalid_email" }, 400);
    if (password.length < 8) return json({ error: "password_too_short", detail: "at least 8 characters" }, 400);
    if (!ROLES.includes(role)) return json({ error: "invalid_role" }, 400);

    const { data, error } = await admin.auth.admin.createUser({
      email,
      password,
      email_confirm: true,
      user_metadata: { full_name: fullName || email.split("@")[0] },
    });
    if (error || !data.user) {
      return json({ error: "create_failed", detail: error?.message ?? "unknown" }, 400);
    }

    // The signup trigger already made the profile row; set its role (service role may change roles).
    const { error: upErr } = await admin
      .from("profiles")
      .update({ role, full_name: fullName || email.split("@")[0] })
      .eq("id", data.user.id);
    if (upErr) return json({ error: "profile_update_failed", detail: upErr.message, user_id: data.user.id }, 500);

    return json({ status: "created", user_id: data.user.id });
  }

  if (action === "set_password") {
    const userId = String(body.user_id ?? "");
    const password = String(body.password ?? "");
    if (!UUID_RE.test(userId)) return json({ error: "invalid_user_id" }, 400);
    if (password.length < 8) return json({ error: "password_too_short", detail: "at least 8 characters" }, 400);
    const { error } = await admin.auth.admin.updateUserById(userId, { password });
    if (error) return json({ error: "update_failed", detail: error.message }, 400);
    return json({ status: "updated" });
  }

  // Profile photos: the ORIGINAL file is uploaded by the browser straight to Storage (signed upload URL, any size up to
  // the bucket limit, no re-compression); a small display copy (thumb/) is what the app shows.
  if (action === "avatar_upload_url") {
    const userId = String(body.user_id ?? "");
    if (!UUID_RE.test(userId)) return json({ error: "invalid_user_id" }, 400);
    const ext = ({ "image/jpeg": "jpg", "image/png": "png", "image/webp": "webp" } as Record<string, string>)[String(body.mime ?? "")];
    if (!ext) return json({ error: "invalid_avatar", detail: "only JPEG, PNG or WebP" }, 400);
    const path = `orig/${userId}-${Date.now()}.${ext}`;
    const { data, error } = await admin.storage.from("avatars").createSignedUploadUrl(path);
    if (error || !data) return json({ error: "upload_url_failed", detail: error?.message ?? "unknown" }, 500);
    return json({ path, token: data.token });
  }

  if (action === "set_avatar") {
    const userId = String(body.user_id ?? "");
    if (!UUID_RE.test(userId)) return json({ error: "invalid_user_id" }, 400);
    const av = String(body.avatar ?? "");
    const bucket = admin.storage.from("avatars");
    // delete every stored file of this user except the one to keep
    const cleanup = async (keepOrig: string | null) => {
      const { data: files } = await bucket.list("orig", { search: userId, limit: 100 });
      const old = (files ?? []).map((f) => `orig/${f.name}`).filter((n) => n.startsWith(`orig/${userId}-`) && n !== keepOrig);
      if (old.length) await bucket.remove(old);
    };
    if (!av) {
      await cleanup(null);
      await bucket.remove([`thumb/${userId}.jpg`]);
      const { error } = await admin.from("profiles").update({ avatar_url: null, avatar_original_url: null }).eq("id", userId);
      if (error) return json({ error: "update_failed", detail: error.message }, 400);
      return json({ status: "removed" });
    }
    const m = /^data:(image\/jpeg);base64,([A-Za-z0-9+/=]+)$/.exec(av);
    if (!m || av.length > 420_000) return json({ error: "invalid_avatar" }, 400);
    const origPath = body.orig_path ? String(body.orig_path) : null;
    if (origPath && !new RegExp(`^orig/${userId}-\\d+\\.(jpg|png|webp)$`).test(origPath)) return json({ error: "invalid_orig_path" }, 400);
    const bytes = Uint8Array.from(atob(m[2]), (c) => c.charCodeAt(0));
    const up = await bucket.upload(`thumb/${userId}.jpg`, bytes, { contentType: "image/jpeg", upsert: true });
    if (up.error) return json({ error: "upload_failed", detail: up.error.message }, 500);
    const v = Date.now();
    const patch: Record<string, string | null> = { avatar_url: bucket.getPublicUrl(`thumb/${userId}.jpg`).data.publicUrl + `?v=${v}` };
    if (origPath) { patch.avatar_original_url = bucket.getPublicUrl(origPath).data.publicUrl; await cleanup(origPath); }
    const { error } = await admin.from("profiles").update(patch).eq("id", userId);
    if (error) return json({ error: "update_failed", detail: error.message }, 400);
    return json({ status: "updated", avatar_url: patch.avatar_url, avatar_original_url: patch.avatar_original_url ?? null });
  }

  if (["update_email", "disable", "enable", "delete"].includes(action)) {
    const userId = String(body.user_id ?? "");
    if (!UUID_RE.test(userId)) return json({ error: "invalid_user_id" }, 400);
    const self = userId === userData.user.id;

    if (action === "update_email") {
      const email = String(body.email ?? "").trim().toLowerCase();
      if (!EMAIL_RE.test(email)) return json({ error: "invalid_email" }, 400);
      const { error } = await admin.auth.admin.updateUserById(userId, { email, email_confirm: true });
      if (error) return json({ error: "update_failed", detail: error.message }, 400);
      return json({ status: "updated" });
    }
    if (action === "enable") {
      const { error } = await admin.auth.admin.updateUserById(userId, { ban_duration: "none" });
      if (error) return json({ error: "update_failed", detail: error.message }, 400);
      return json({ status: "enabled" });
    }
    // disable and delete: never on yourself, never on the last active admin
    if (self) return json({ error: "cannot_on_self", detail: "you cannot disable or delete your own account" }, 400);
    const { data: target } = await admin.from("profiles").select("role").eq("id", userId).maybeSingle();
    if (target?.role === "admin") {
      const { count } = await admin.from("profiles").select("id", { count: "exact", head: true }).eq("role", "admin");
      if ((count ?? 0) <= 1) return json({ error: "last_admin", detail: "this is the only admin" }, 400);
    }
    if (action === "disable") {
      const { error } = await admin.auth.admin.updateUserById(userId, { ban_duration: "876000h" });
      if (error) return json({ error: "update_failed", detail: error.message }, 400);
      await admin.from("profiles").update({ role: "none" }).eq("id", userId);
      return json({ status: "disabled" });
    }
    // delete
    const { error } = await admin.auth.admin.deleteUser(userId);
    if (error) {
      const hist = /foreign key|violates|database error/i.test(error.message);
      return json({ error: hist ? "has_history" : "delete_failed", detail: error.message }, hist ? 409 : 400);
    }
    return json({ status: "deleted" });
  }

  return json({ error: "unknown_action" }, 400);
});
