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
/** inline markers -> HTML: **bold** *italic* [text](https://url) [^n] (footnote ref) \\* (literal star) */
export function inl(t: string): string {
  return esc(t).replace(/\\\*/g, "\u0001").replace(/\\\[\^/g, "\u0002")
    .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, (_m, a, u) => `<a href="${u}">${a}</a>`)
    .replace(/\*\*([^*]+?)\*\*/g, "<strong>$1</strong>").replace(/\*([^*\n]+?)\*/g, "<em>$1</em>")
    .replace(/\[\^(\d+)\]/g, (_m, n) => `<sup id="fnref-${n}" class="fnref"><a href="#fn-${n}">[${n}]</a></sup>`)
    .replace(/\u0001/g, "*").replace(/\u0002/g, "[^");
}
export const plainText = (t: string) =>
  t.replace(/\\\*/g, "\u0001").replace(/\[([^\]]+)\]\(https?:\/\/[^\s)]+\)/g, "$1").replace(/\[\^\d+\]/g, "").replace(/\*\*?/g, "").replace(/\u0001/g, "*").trim();

export function toHtml(segs: { kind: string; text: string; position: number; level?: number | null }[], imgs: Img[] = []): string {
  const out: string[] = [];
  const stack: string[] = [];            // open <ul>/<ol> tags
  const notes: { n: string; html: string }[] = [];
  const placed = new Set<Img>();
  const flush = () => { while (stack.length) out.push(`</${stack.pop()}>`); };
  for (const s of segs) {
    const t = s.text.trim();
    if (t) {
      if (s.kind === "footnote") {
        const m = /^\[\^(\d+)\]\s*([\s\S]*)$/.exec(t);
        if (m) notes.push({ n: m[1], html: inl(m[2]) });
      } else if (s.kind === "list_item") {
        const ordered = /^\d+\.\s/.test(t), body = t.replace(/^(?:\d+\.|-)\s+/, ""), depth = Math.min(Math.max(s.level ?? 1, 1), 4);
        while (stack.length > depth) out.push(`</${stack.pop()}>`);
        while (stack.length < depth) { const tag = ordered ? "ol" : "ul"; stack.push(tag); out.push(`<${tag}>`); }
        out.push(`<li>${inl(body)}</li>`);
      } else {
        flush();
        if (s.kind === "heading") { const h = Math.min((s.level ?? 1) + 1, 5); out.push(`<h${h}>${inl(t)}</h${h}>`); }
        else out.push(`<p>${inl(t).replace(/\n/g, "<br>")}</p>`);
      }
    }
    for (const i of imgs) if (!placed.has(i) && i.after === s.position) { flush(); out.push(figure(i)); placed.add(i); }
  }
  flush();
  for (const i of imgs) if (!placed.has(i)) out.push(figure(i));
  if (notes.length) out.push(`<hr><ol class="footnotes">${notes.map((x) => `<li id="fn-${x.n}">${x.html} <a href="#fnref-${x.n}" aria-label="Back to text">&#8617;</a></li>`).join("")}</ol>`);
  return out.join("\n");
}

class WpError extends Error {}

async function wp(base: string, auth: string, path: string, method = "GET", body?: unknown) {
  let res: Response;
  try {
    // path starting with "@" uses the ?rest_route= form, which some host firewalls let through when /wp-json/... is blocked
    const url = path.startsWith("@") ? `${base}/?rest_route=${path.slice(1).replace("?", "&")}` : `${base}/wp-json${path}`;
    res = await fetch(url, {
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

/** For a custom post type (REST base), find its category-like and tag-like taxonomies (REST bases) when none was configured. */
async function taxOf(base: string, auth: string, restBase: string): Promise<{ cat: string; tag: string }> {
  const out = { cat: "", tag: "" };
  try {
    const t = await wp(base, auth, "/wp/v2/types?context=edit").catch(() => wp(base, auth, "/wp/v2/types"));
    const ty = Object.values(t as Record<string, any>).find((x: any) => x?.rest_base === restBase) as any;
    if (!ty) return out;
    const tx = await wp(base, auth, `/wp/v2/taxonomies?type=${encodeURIComponent(ty.slug)}`);
    const list = Object.values(tx as Record<string, any>).filter((x: any) => x?.rest_base && !/^(post_format|nav_menu|wp_)/.test(x.slug));
    const cats = list.filter((x: any) => x.hierarchical), tags = list.filter((x: any) => !x.hierarchical);
    out.cat = (cats.find((x: any) => /categ/i.test(x.slug)) ?? cats[0])?.rest_base ?? "";
    out.tag = (tags.find((x: any) => /tag/i.test(x.slug)) ?? tags[0])?.rest_base ?? "";
  } catch { /* leave empty */ }
  return out;
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
      let tax = /^[a-z0-9_-]+$/i.test(body?.tax ?? "") ? body.tax : "";
      if (!tax && /^[a-z0-9_-]+$/i.test(body?.type ?? "") && body.type !== "posts") tax = (await taxOf(base, auth, body.type)).cat;
      if (!tax) tax = "categories";
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
      // 1) the site's own small route (functions.php snippet, path rise/v1/scholars): immune to firewalls that block /wp/v2/users
      let custom: any = null;
      for (const p of [`/rise/v1/scholars?role=${roleSlug}`, `@/rise/v1/scholars?role=${roleSlug}`]) {
        try { custom = await wp(base, auth, p); if (Array.isArray(custom)) break; custom = null; } catch { /* try the next route */ }
      }
      if (Array.isArray(custom)) {
        all.push(...custom.map((x: any) => ({ id: Number(x.id), name: String(x.name), slug: String(x.slug ?? "") })));
        all.sort((a, b) => a.name.localeCompare(b.name));
        return json({ ok: true, role: roleSlug, authors: all, via: "custom" });
      }
      for (let page = 1; page <= 10; page++) {
        const q = `/wp/v2/users?roles=${roleSlug}&per_page=100&page=${page}&_fields=id,name,slug`;
        let us: any = null, lastErr: unknown = null;
        for (const p of [q + "&context=edit", "@" + q + "&context=edit", "@" + q, q]) {
          try { us = await wp(base, auth, p); break; } catch (e) { lastErr = e; }
        }
        if (us === null) { if (page > 1) break; throw lastErr; }
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
    let catKey = c[`wp_catkey_${doc.source_kind}`] || (isPosts ? "categories" : "");
    let tagKey = c[`wp_tagkey_${doc.source_kind}`] || (isPosts ? "tags" : "");
    if (action === "send" && !isPosts && (!catKey || !tagKey)) { const f = await taxOf(base, auth, restBase); catKey = catKey || f.cat; tagKey = tagKey || f.tag; }

    if (action === "draft") {
      if (!doc.wp_post_id) return json({ ok: true, skipped: true });
      const r = await wp(base, auth, `/wp/v2/${restBase}/${doc.wp_post_id}`, "POST", { status: "draft" });
      await admin.from("documents").update({ wp_status: "draft", wp_error: null }).eq("id", id);
      return json({ ok: true, url: r.link ?? null });
    }

    // send
    if (!["approved", "published"].includes(doc.status)) return json({ error: "not_ready", detail: "only approved translations can be sent" }, 409);
    const { data: segs, error: se } = await admin.from("segments").select("kind,level,current_text,position").eq("document_id", id).order("position");
    if (se || !segs?.length) return json({ error: "no_text" }, 409);
    let items = segs.map((s: any) => ({ kind: s.kind as string, text: (s.current_text ?? "") as string, position: s.position as number, level: (s.level ?? null) as number | null }));
    let title = (doc.publish_title ?? "").trim();
    if (!title) {
      const first = items[0];
      if (first && first.kind === "heading" && first.text.trim()) { title = plainText(first.text); items = items.slice(1); }
      else title = doc.title;
    } else if (items[0]?.kind === "heading" && plainText(items[0].text).toLowerCase() === title.toLowerCase()) {
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
    // The chosen scholar must be the post's author. Some post types do not accept the author through the REST API (no "author"
    // support), in which case WordPress silently keeps the API account. Check the answer and, if needed, use the plugin route.
    let authorWarning: string | null = null;
    const wantAuthor = doc.pub_author_id ? Number(doc.pub_author_id) : 0;
    if (wantAuthor && Number(r.author) !== wantAuthor) {
      let fixed = false;
      for (const path of ["/rise/v1/set-author", "@/rise/v1/set-author"]) {
        try { const x = await wp(base, auth, path, "POST", { post_id: r.id, author: wantAuthor }); if (x?.ok) { fixed = true; break; } } catch { /* try the next route */ }
      }
      if (!fixed) authorWarning = "\u0646\u0648\u06cc\u0633\u0646\u062f\u0647\u0654 \u0627\u0646\u062a\u062e\u0627\u0628\u200c\u0634\u062f\u0647 \u062b\u0628\u062a \u0646\u0634\u062f \u0648 \u0646\u0648\u0634\u062a\u0647 \u0628\u0627 \u062d\u0633\u0627\u0628 \u0627\u062a\u0635\u0627\u0644 \u062b\u0628\u062a \u0634\u062f. \u0627\u0641\u0632\u0648\u0646\u0647\u0654 Rise Scholars Route \u0631\u0627 \u0628\u0647 \u0646\u0633\u062e\u0647\u0654 \u06f1.\u06f1 \u0628\u0647\u200c\u0631\u0648\u0632 \u06a9\u0646\u06cc\u062f.";
    }
    await admin.from("documents").update({
      wp_post_id: r.id, wp_url: r.link ?? null, wp_status: r.status ?? status, wp_error: null, wp_sent_at: new Date().toISOString(),
    }).eq("id", id);
    return json({ ok: true, post_id: r.id, url: r.link ?? null, wp_status: r.status ?? status, images_sent: approvedImgs.length, images_skipped: skipped, has_featured: !!featured, author_warning: authorWarning, author: r.author ?? null });
  } catch (e) {
    const m = e instanceof Error ? e.message : String(e);
    return json({ error: "wordpress_failed", detail: m }, e instanceof WpError ? 502 : 500);
  }
});
