// Edge Function: publish-wordpress
//
// Sends the approved English translation of a document to WordPress through the standard REST API
// (Application Passwords, WordPress 5.6+). Deploy with verify_jwt = true.
//
// POST { action: "test" }                       admin: checks the connection, returns site name + user
// POST { action: "categories" }                 admin/supervisor/reviewer/news editor: the site's categories [{id, name}]
// POST { action: "send", document_id }          admin/supervisor: creates (or updates) the WordPress post
// POST { action: "draft", document_id }         admin/supervisor: sets the existing post back to draft
//
// Settings (app_config): wp_url, wp_user, wp_status (draft|publish), wp_post_type (REST base, default "posts"),
// wp_cat_news / wp_cat_scholaria / wp_cat_journal (category ids, comma separated).
// Secret (app_secrets, saved from the app): WP_APP_PASSWORD. Fallback: environment secret of the same name.
import { createClient } from "npm:@supabase/supabase-js@2";

const SUPABASE_URL = Deno.env.get("SUPABASE_URL")!;
const ANON_KEY = Deno.env.get("SUPABASE_ANON_KEY")!;
const SERVICE_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!;
const ALLOWED_ORIGIN = Deno.env.get("ALLOWED_ORIGIN") ?? "*";
const UA = "RiseContentPlatform/1.0 (+https://riseislamicstudies.com)";
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

const cors = {
  "Access-Control-Allow-Origin": ALLOWED_ORIGIN,
  "Access-Control-Allow-Headers": "authorization, x-client-info, apikey, content-type",
  "Access-Control-Allow-Methods": "POST, OPTIONS",
};
const json = (b: unknown, status = 200) =>
  new Response(JSON.stringify(b), { status, headers: { ...cors, "Content-Type": "application/json" } });

const esc = (s: string) => s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");

export interface Img { src: string; alt: string; caption: string; after: number | null }

const figure = (i: Img) =>
  `<figure class="wp-block-image"><img src="${esc(i.src)}" alt="${esc(i.alt)}"/>${i.caption ? `<figcaption>${esc(i.caption)}</figcaption>` : ""}</figure>`;

/** English segments -> WordPress HTML. Consecutive list items share one <ul>.
 *  Inline images go after the segment whose position they name, or at the end. */
export function toHtml(segs: { kind: string; text: string; position: number }[], imgs: Img[] = []): string {
  const out: string[] = [];
  let inList = false;
  const placed = new Set<Img>();
  const flush = () => { if (inList) { out.push("</ul>"); inList = false; } };
  for (const s of segs) {
    const t = s.text.trim();
    if (t) {
      if (s.kind === "list_item") {
        if (!inList) { out.push("<ul>"); inList = true; }
        out.push(`<li>${esc(t)}</li>`);
      } else {
        flush();
        out.push(s.kind === "heading" ? `<h2>${esc(t)}</h2>` : `<p>${esc(t).replace(/\n/g, "<br>")}</p>`);
      }
    }
    for (const i of imgs) if (!placed.has(i) && i.after === s.position) { flush(); out.push(figure(i)); placed.add(i); }
  }
  flush();
  for (const i of imgs) if (!placed.has(i)) out.push(figure(i));
  return out.join("\n");
}

class WpError extends Error {}

async function wp(base: string, auth: string, path: string, method = "GET", body?: unknown) {
  let res: Response;
  try {
    res = await fetch(`${base}/wp-json${path}`, {
      method,
      headers: { Authorization: auth, "Content-Type": "application/json", Accept: "application/json", "User-Agent": UA },
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: AbortSignal.timeout(25_000),
    });
  } catch (e) {
    throw new WpError(`cannot_reach_site: ${e instanceof Error ? e.message : e}`);
  }
  const text = await res.text();
  let data: any = null;
  try { data = JSON.parse(text); } catch { /* not JSON */ }
  if (!res.ok) {
    const code = data?.code ? `${data.code}: ` : "";
    const msg = data?.message ?? text.slice(0, 200);
    throw new WpError(`wordpress_${res.status}: ${code}${msg}`);
  }
  if (data === null) throw new WpError("wordpress_returned_non_json (is the REST API blocked or the address wrong?)");
  return data;
}

async function wpBinary(base: string, auth: string, path: string, bytes: Uint8Array, mime: string, filename: string) {
  let res: Response;
  try {
    res = await fetch(`${base}/wp-json${path}`, {
      method: "POST",
      headers: { Authorization: auth, "Content-Type": mime, "Content-Disposition": `attachment; filename="${filename}"`, Accept: "application/json", "User-Agent": UA },
      body: bytes,
      signal: AbortSignal.timeout(60_000),
    });
  } catch (e) { throw new WpError(`cannot_reach_site: ${e instanceof Error ? e.message : e}`); }
  const text = await res.text();
  let data: any = null; try { data = JSON.parse(text); } catch { /* not JSON */ }
  if (!res.ok || !data?.id) throw new WpError(`wordpress_media_${res.status}: ${data?.code ? data.code + ": " : ""}${data?.message ?? text.slice(0, 200)}`);
  return data as { id: number; source_url: string };
}

/** Find a tag by name (exact, case-insensitive) or create it. */
async function tagId(base: string, auth: string, name: string, tagKey = "tags"): Promise<number> {
  const found = await wp(base, auth, `/wp/v2/${tagKey}?per_page=20&search=${encodeURIComponent(name)}&_fields=id,name`);
  const hit = (found as { id: number; name: string }[]).find((t) => t.name.replace(/&amp;/g, "&").toLowerCase() === name.toLowerCase());
  if (hit) return hit.id;
  const made = await wp(base, auth, `/wp/v2/${tagKey}`, "POST", { name });
  return made.id;
}

Deno.serve(async (req) => {
  if (req.method === "OPTIONS") return new Response("ok", { headers: cors });
  if (req.method !== "POST") return json({ error: "method_not_allowed" }, 405);

  const authHeader = req.headers.get("Authorization");
  if (!authHeader) return json({ error: "missing_authorization" }, 401);
  const userClient = createClient(SUPABASE_URL, ANON_KEY, { global: { headers: { Authorization: authHeader } }, auth: { persistSession: false } });
  const { data: u, error: ue } = await userClient.auth.getUser();
  if (ue || !u.user) return json({ error: "invalid_token" }, 401);
  const { data: prof } = await userClient.from("profiles").select("role").eq("id", u.user.id).single();
  const role = prof?.role as string | undefined;
  const body = await req.json().catch(() => null);
  const action = body?.action;
  if (!["test", "categories", "send", "draft", "types", "authors"].includes(action)) return json({ error: "bad_action" }, 400);
  const allowed = action === "categories" ? ["admin", "supervisor", "reviewer", "news_editor"] : action === "authors" ? ["admin", "supervisor"] : action === "test" || action === "types" ? ["admin"] : ["admin", "supervisor"];
  if (!role || !allowed.includes(role)) return json({ error: "forbidden" }, 403);

  const admin = createClient(SUPABASE_URL, SERVICE_KEY, { auth: { persistSession: false } });
  const { data: cfgRows } = await admin.from("app_config").select("key,value").like("key", "wp\\_%");
  const c: Record<string, string> = {};
  (cfgRows ?? []).forEach((r: { key: string; value: string }) => { c[r.key] = r.value ?? ""; });
  const { data: sec } = await admin.from("app_secrets").select("value").eq("name", "WP_APP_PASSWORD").maybeSingle();
  const password = sec?.value || Deno.env.get("WP_APP_PASSWORD") || "";

  const base = (c.wp_url || "").trim().replace(/\/+$/, "");
  if (!/^https:\/\/[^\s]+$/i.test(base)) return json({ error: "wp_not_configured", detail: "wp_url must be an https address" }, 400);
  if (!c.wp_user || !password) return json({ error: "wp_not_configured", detail: "user or application password is missing" }, 400);
  const auth = "Basic " + btoa(`${c.wp_user}:${password.replace(/\s+/g, " ")}`);
  const restBase0 = /^[a-z0-9_-]+$/i.test(c.wp_post_type || "") ? c.wp_post_type : "posts";

  try {
    if (action === "test") {
      // Some hosts' firewalls block individual REST URLs, so try several routes and report what worked.
      let user = "", canPublish: boolean | null = null, authOk = false, lastErr = "";
      for (const path of ["/wp/v2/users/me?context=edit", "/wp/v2/users/me"]) {
        try {
          const me = await wp(base, auth, path);
          user = me.name ?? me.slug ?? ""; authOk = true;
          if (me.capabilities) canPublish = !!me.capabilities.publish_posts;
          break;
        } catch (e) { lastErr = e instanceof Error ? e.message : String(e); }
      }
      if (!authOk) {
        // drafts are only listed for authenticated users with edit rights
        try { await wp(base, auth, "/wp/v2/posts?status=draft&per_page=1&_fields=id"); authOk = true; }
        catch (e) { lastErr = e instanceof Error ? e.message : String(e); }
      }
      if (!authOk) throw new WpError(lastErr || "authentication_failed");
      let site = "";
      try { site = (await wp(base, auth, "/")).name ?? ""; } catch { /* optional */ }
      return json({ ok: true, site, user, can_publish: canPublish });
    }
    if (action === "categories") {
      const tax = /^[a-z0-9_-]+$/i.test(body?.tax ?? "") ? body.tax : "categories";
      const cats = await wp(base, auth, `/wp/v2/${tax}?per_page=100&_fields=id,name`);
      return json({ ok: true, categories: cats });
    }
    if (action === "types") {
      // the site's post types (REST bases) so the admin can pick the right one for each kind of content
      const t = await wp(base, auth, "/wp/v2/types?context=edit").catch(() => wp(base, auth, "/wp/v2/types"));
      const out = Object.values(t as Record<string, any>).filter((x) => x?.rest_base && x.slug !== "attachment" && !/^wp_|nav_menu/.test(x.slug))
        .map((x) => ({ slug: x.slug, name: x.name, rest_base: x.rest_base, taxonomies: x.taxonomies ?? [] }));
      return json({ ok: true, types: out });
    }
    if (action === "authors") {
      // users with the scholar role (configurable: wp_author_role); scholars have an intro card on the site, so no byline is added
      const roleSlug = /^[a-z0-9_-]+$/i.test(c.wp_author_role || "") ? c.wp_author_role : "scholar";
      const all: { id: number; name: string; slug: string }[] = [];
      for (let page = 1; page <= 10; page++) {
        const us = await wp(base, auth, `/wp/v2/users?roles=${roleSlug}&per_page=100&page=${page}&context=edit&_fields=id,name,slug`).catch((e) => { if (page > 1) return []; throw e; });
        if (!Array.isArray(us)) break;
        all.push(...us.map((x: any) => ({ id: x.id, name: x.name, slug: x.slug })));
        if (us.length < 100) break;
      }
      all.sort((a, b) => a.name.localeCompare(b.name));
      return json({ ok: true, role: roleSlug, authors: all });
    }

    const id = body?.document_id;
    if (typeof id !== "string" || !UUID_RE.test(id)) return json({ error: "document_id_required" }, 400);
    const { data: doc, error: de } = await admin.from("documents")
      .select("id,title,status,source_kind,publish_title,wp_post_id,pub_slug,pub_excerpt,pub_tags,pub_category,pub_seo_title,pub_seo_desc,pub_seo_focus,pub_at,pub_author,pub_author_id").eq("id", id).maybeSingle();
    if (de || !doc) return json({ error: "document_not_found" }, 404);
    // each kind of content may live in its own post type (e.g. Scholaria articles); wp_type_<kind> overrides wp_post_type
    const kindType = c[`wp_type_${doc.source_kind}`];
    const restBase = /^[a-z0-9_-]+$/i.test(kindType || "") ? kindType : restBase0;
    const isPosts = restBase === "posts";
    const catKey = c[`wp_catkey_${doc.source_kind}`] || (isPosts ? "categories" : "");
    const tagKey = c[`wp_tagkey_${doc.source_kind}`] || (isPosts ? "tags" : "");

    if (action === "draft") {
      if (!doc.wp_post_id) return json({ ok: true, skipped: true });
      const r = await wp(base, auth, `/wp/v2/${restBase}/${doc.wp_post_id}`, "POST", { status: "draft" });
      await admin.from("documents").update({ wp_status: "draft", wp_error: null }).eq("id", id);
      return json({ ok: true, url: r.link ?? null });
    }

    // send
    if (!["approved", "published"].includes(doc.status)) return json({ error: "not_ready", detail: "only approved translations can be sent" }, 409);
    const { data: segs, error: se } = await admin.from("segments").select("kind,current_text,position").eq("document_id", id).order("position");
    if (se || !segs?.length) return json({ error: "no_text" }, 409);
    let items = segs.map((s: any) => ({ kind: s.kind as string, text: (s.current_text ?? "") as string, position: s.position as number }));
    let title = (doc.publish_title ?? "").trim();
    if (!title) {
      const first = items[0];
      if (first && first.kind === "heading" && first.text.trim()) { title = first.text.trim(); items = items.slice(1); }
      else title = doc.title;
    } else if (items[0]?.kind === "heading" && items[0].text.trim().toLowerCase() === title.toLowerCase()) {
      items = items.slice(1); // the title is already the first heading: do not repeat it in the body
    }
    // images: only the ones the content reviewer approved are sent
    const { data: imgRows } = await admin.from("document_images").select("*").eq("document_id", id).order("created_at");
    const approvedImgs = (imgRows ?? []).filter((x: any) => x.approved);
    const skipped = (imgRows ?? []).length - approvedImgs.length;
    const media: Record<string, { id: number; url: string }> = {};
    for (const im of approvedImgs as any[]) {
      if (im.wp_media_id && im.wp_url) { media[im.id] = { id: im.wp_media_id, url: im.wp_url }; continue; }
      const dl = await admin.storage.from("doc-images").download(im.path);
      if (dl.error || !dl.data) throw new WpError(`image_download_failed: ${im.filename ?? im.path}`);
      const bytes = new Uint8Array(await dl.data.arrayBuffer());
      const ext = im.mime === "image/png" ? "png" : im.mime === "image/webp" ? "webp" : "jpg";
      const base0 = (im.filename ?? "image").replace(/\.[^.]+$/, "").replace(/[^A-Za-z0-9_-]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 60) || "image";
      const up = await wpBinary(base, auth, "/wp/v2/media", bytes, im.mime, `${base0}.${ext}`);
      const alt = (im.alt_text ?? "").trim() || title;
      await wp(base, auth, `/wp/v2/media/${up.id}`, "POST", { alt_text: alt, caption: im.caption ?? "", title: im.caption || title });
      media[im.id] = { id: up.id, url: up.source_url };
      await admin.from("document_images").update({ wp_media_id: up.id, wp_url: up.source_url }).eq("id", im.id);
    }
    const featured = approvedImgs.find((x: any) => x.kind === "featured") as any;
    const inline: Img[] = (approvedImgs as any[]).filter((x) => x.kind !== "featured").map((x) => ({
      src: media[x.id].url, alt: (x.alt_text ?? "").trim() || title, caption: x.caption ?? "", after: x.after_position ?? null,
    }));
    let html = toHtml(items, inline);
    if (!html) return json({ error: "no_text" }, 409);
    const when = doc.pub_at ? new Date(doc.pub_at) : null;
    const future = !!when && when.getTime() > Date.now() + 60_000;
    let status = c.wp_status === "publish" ? "publish" : "draft";
    if (status === "publish" && future) status = "future";
    const post: Record<string, unknown> = { title, content: html, status };
    if (when && (future || status === "draft")) post.date_gmt = when.toISOString().slice(0, 19);
    if (doc.pub_slug) post.slug = doc.pub_slug;
    if (doc.pub_excerpt) post.excerpt = doc.pub_excerpt;
    if (featured) post.featured_media = media[featured.id].id;
    if (doc.pub_author_id) post.author = Number(doc.pub_author_id);
    if (catKey) {
      const catId = doc.pub_category ? [Number(doc.pub_category)] :
        (c[`wp_cat_${doc.source_kind}`] || "").split(",").map((x) => Number(x.trim())).filter((n) => Number.isInteger(n) && n > 0);
      if (catId.length) post[catKey] = catId;
    }
    if (tagKey) {
      const tagIds: number[] = [];
      for (const name of (doc.pub_tags ?? []) as string[]) tagIds.push(await tagId(base, auth, name, tagKey));
      if (tagIds.length) post[tagKey] = tagIds;
    }
    const meta: Record<string, string> = {};
    if (c.wp_seo_title_key && doc.pub_seo_title) meta[c.wp_seo_title_key] = doc.pub_seo_title;
    if (c.wp_seo_desc_key && doc.pub_seo_desc) meta[c.wp_seo_desc_key] = doc.pub_seo_desc;
    if (c.wp_seo_focus_key && doc.pub_seo_focus) meta[c.wp_seo_focus_key] = doc.pub_seo_focus;
    if (Object.keys(meta).length) post.meta = meta;

    let r: any;
    try {
      r = doc.wp_post_id
        ? await wp(base, auth, `/wp/v2/${restBase}/${doc.wp_post_id}`, "POST", post)
        : await wp(base, auth, `/wp/v2/${restBase}`, "POST", post);
    } catch (e) {
      const m = e instanceof Error ? e.message : String(e);
      await admin.from("documents").update({ wp_error: m.slice(0, 500) }).eq("id", id);
      throw e;
    }
    await admin.from("documents").update({
      wp_post_id: r.id, wp_url: r.link ?? null, wp_status: r.status ?? status, wp_error: null, wp_sent_at: new Date().toISOString(),
    }).eq("id", id);
    return json({ ok: true, post_id: r.id, url: r.link ?? null, wp_status: r.status ?? status, images_sent: approvedImgs.length, images_skipped: skipped, has_featured: !!featured });
  } catch (e) {
    const m = e instanceof Error ? e.message : String(e);
    return json({ error: "wordpress_failed", detail: m }, e instanceof WpError ? 502 : 500);
  }
});
