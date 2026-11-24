/*!
 * rise-client.js — جایگزین سبک supabase-js برای بک‌اند PHP سکوی رایز.
 * همان بخشی از API را شبیه‌سازی می‌کند که index.html استفاده می‌کند:
 *   from().select/insert/update/upsert/delete + فیلترها، rpc، auth (ایمیل/رمز)، storage، functions.invoke، channel (polling).
 * خروجی همهٔ فراخوانی‌ها مثل supabase-js به شکل {data, error} است.
 * window.supabase.createClient(baseUrl, _ignoredKey) → کلاینت؛ baseUrl آدرس پوشهٔ backend است.
 */
(function () {
  'use strict';
  var TOKEN_KEY = 'rise_token_v1';

  function getToken() { try { return localStorage.getItem(TOKEN_KEY) || ''; } catch (_) { return ''; } }
  function setToken(t) { try { if (t) localStorage.setItem(TOKEN_KEY, t); else localStorage.removeItem(TOKEN_KEY); } catch (_) { /* ignore */ } }

  function mkError(e, fallback) {
    if (e && typeof e === 'object') return { message: e.message || fallback || 'error', code: e.code || null, details: e.details || null };
    return { message: fallback || String(e) };
  }

  function createClient(baseUrl) {
    var api = String(baseUrl || '').replace(/\/+$/, '') + '/api';
    var reloading = false;

    function headers(extra) {
      var h = Object.assign({}, extra || {});
      var t = getToken();
      if (t) h['X-Rise-Token'] = t;
      return h;
    }

    // یک POST با بدنهٔ JSON به api/<file>؛ {status, body}
    async function post(file, payload, opts) {
      opts = opts || {};
      var res = await fetch(api + '/' + file + (opts.query || ''), {
        method: 'POST',
        headers: headers(opts.raw ? opts.headers : { 'Content-Type': 'application/json' }),
        body: opts.raw ? payload : JSON.stringify(payload || {}),
        cache: 'no-store'
      });
      var text = await res.text(), body = null;
      try { body = text ? JSON.parse(text) : null; } catch (_) { body = { error: { message: text.slice(0, 200) || ('HTTP ' + res.status) } }; }
      if (res.status === 401 && getToken() && !opts.noReload) {
        var m = body && body.error && body.error.message;
        if (m === 'invalid_token' || m === 'not_authenticated') {
          setToken('');
          if (!reloading) { reloading = true; try { location.reload(); } catch (_) { /* ignore */ } }
        }
      }
      return { status: res.status, body: body };
    }

    async function call(file, payload, opts) {
      try {
        var r = await post(file, payload, opts);
        if (r.status >= 200 && r.status < 300 && r.body && !r.body.error) return { data: r.body.data === undefined ? null : r.body.data, error: null, status: r.status };
        var er = (r.body && r.body.error) || { message: 'HTTP ' + r.status };
        if (typeof er === 'string') er = { message: er };
        return { data: null, error: mkError(er), status: r.status };
      } catch (e) {
        return { data: null, error: { message: 'Failed to fetch' }, status: 0 };
      }
    }

    // ------------------------------------------------------------ query builder
    function QB(table) {
      this.t = table; this.op = 'select'; this.cols = '*'; this.f = []; this.orStr = null; this.ord = [];
      this.lim = null; this.off = null; this.sg = null; this.vals = null; this.conflict = null; this.ret = null;
    }
    QB.prototype.select = function (cols) {
      if (this.op === 'select') this.cols = cols || '*'; else this.ret = cols || '*';
      return this;
    };
    QB.prototype.insert = function (v) { this.op = 'insert'; this.vals = v; return this; };
    QB.prototype.update = function (v) { this.op = 'update'; this.vals = v; return this; };
    QB.prototype.upsert = function (v, o) { this.op = 'upsert'; this.vals = v; this.conflict = (o && o.onConflict) || null; return this; };
    QB.prototype.delete = function () { this.op = 'delete'; return this; };
    ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'like', 'ilike', 'is', 'in'].forEach(function (op) {
      QB.prototype[op] = function (col, val) { this.f.push([op, col, val]); return this; };
    });
    QB.prototype.filter = function (col, op, val) { this.f.push([op, col, val]); return this; };
    QB.prototype.or = function (s) { this.orStr = s; return this; };
    QB.prototype.order = function (col, o) { this.ord.push([col, !(o && o.ascending === false)]); return this; };
    QB.prototype.limit = function (n) { this.lim = n; return this; };
    QB.prototype.range = function (a, b) { this.off = a; this.lim = b - a + 1; return this; };
    QB.prototype.single = function () { this.sg = 'single'; return this; };
    QB.prototype.maybeSingle = function () { this.sg = 'maybe'; return this; };
    ['not', 'contains', 'overlaps', 'textSearch', 'csv'].forEach(function (n) {
      QB.prototype[n] = function () { throw new Error('rise-client: .' + n + '() is not supported'); };
    });
    QB.prototype.then = function (ok, bad) {
      var self = this;
      return call('rest.php', {
        table: self.t, op: self.op, columns: self.cols, filters: self.f, or: self.orStr, order: self.ord,
        limit: self.lim, offset: self.off, single: self.sg, values: self.vals, onConflict: self.conflict, returning: self.ret
      }).then(ok, bad);
    };

    // ------------------------------------------------------------ rpc
    function RpcQ(name, args) { this.n = name; this.a = args || {}; }
    RpcQ.prototype.single = function () { return this; };
    RpcQ.prototype.maybeSingle = function () { return this; };
    RpcQ.prototype.then = function (ok, bad) { return call('rpc.php', { name: this.n, args: this.a }).then(ok, bad); };

    // ------------------------------------------------------------ auth
    var auth = {
      async signInWithPassword(c) {
        var r = await call('auth.php', { action: 'login', email: c.email, password: c.password }, { noReload: true });
        if (r.error) return { data: { user: null, session: null }, error: r.error };
        setToken(r.data.access_token);
        return { data: { user: r.data.user, session: { access_token: r.data.access_token, user: r.data.user } }, error: null };
      },
      async getSession() {
        if (!getToken()) return { data: { session: null }, error: null };
        var r = await call('auth.php', { action: 'session' }, { noReload: true });
        if (r.error || !r.data) { if (!r.error || r.status === 401) setToken(''); return { data: { session: null }, error: null }; }
        return { data: { session: { access_token: getToken(), user: r.data.user } }, error: null };
      },
      async signOut() {
        try { await call('auth.php', { action: 'logout' }, { noReload: true }); } catch (_) { /* ignore */ }
        setToken('');
        return { error: null };
      }
    };

    // ------------------------------------------------------------ storage
    function bucket(name) {
      function abs(u) { return String(u).replace(/^api\//, api + '/'); }
      return {
        async upload(path, file, o) {
          o = o || {};
          var h = {};
          if (o.contentType) h['Content-Type'] = o.contentType;
          var q = '?op=upload&b=' + encodeURIComponent(name) + '&p=' + encodeURIComponent(path) + (o.upsert ? '&upsert=1' : '');
          return call('storage.php', file, { raw: true, headers: h, query: q });
        },
        remove: function (paths) { return call('storage.php', { bucket: name, paths: paths }, { query: '?op=remove' }); },
        async createSignedUrls(paths, ttl) {
          var r = await call('storage.php', { bucket: name, paths: paths, expires: ttl || 3600 }, { query: '?op=sign' });
          if (r.error) return r;
          r.data = (r.data || []).map(function (x) { return { path: x.path, signedUrl: x.signedUrl ? abs(x.signedUrl) : null, error: x.error }; });
          return r;
        },
        async createSignedUrl(path, ttl) {
          var r = await this.createSignedUrls([path], ttl);
          if (r.error) return r;
          var x = (r.data || [])[0];
          return x && x.signedUrl ? { data: { signedUrl: x.signedUrl }, error: null } : { data: null, error: { message: 'Object not found' } };
        },
        getPublicUrl: function (path) { return { data: { publicUrl: api + '/file.php?b=' + encodeURIComponent(name) + '&p=' + encodeURIComponent(path) } }; }
      };
    }

    // ------------------------------------------------------------ edge functions
    var functions = {
      async invoke(name, opt) {
        try {
          var r = await post('fn.php', { name: name, body: (opt && opt.body) || {} });
          if (r.status >= 200 && r.status < 300) return { data: r.body, error: null };
          var b = r.body;
          return { data: null, error: { message: 'Edge Function returned a non-2xx status code', context: { status: r.status, json: function () { return Promise.resolve(b || {}); } } } };
        } catch (e) {
          return { data: null, error: { message: 'Failed to send a request to the Edge Function' } };
        }
      }
    };

    // ------------------------------------------------------------ realtime (polling)
    function Channel(name) { this.name = name; this.h = []; this.timer = null; this.alive = false; }
    Channel.prototype.on = function (kind, spec, cb) { this.h.push({ spec: spec || {}, cb: cb, last: null }); return this; };
    Channel.prototype.subscribe = function () {
      var self = this; self.alive = true;
      function filterVal(f) { var m = /^user_id=eq\.(.+)$/.exec(f || ''); return m ? m[1] : null; }
      async function prime() {
        for (var i = 0; i < self.h.length; i++) {
          var h = self.h[i];
          if (h.spec.table === 'notifications') {
            var r = await new QB('notifications').select('created_at').order('created_at', { ascending: false }).limit(1);
            h.last = (r.data && r.data[0] && r.data[0].created_at) || new Date().toISOString();
          } else if (h.spec.table === 'documents') {
            var d = await new QB('documents').select('updated_at').order('updated_at', { ascending: false }).limit(1);
            h.last = (d.data && d.data[0] && d.data[0].updated_at) || '';
          }
        }
      }
      async function tick() {
        if (!self.alive || !getToken()) return;
        for (var i = 0; i < self.h.length; i++) {
          var h = self.h[i];
          try {
            if (h.spec.table === 'notifications') {
              var q = new QB('notifications').select('*').gt('created_at', h.last).order('created_at', { ascending: true });
              var uid = filterVal(h.spec.filter); if (uid) q = q.eq('user_id', uid);
              var r = await q;
              (r.data || []).forEach(function (row) { h.last = row.created_at; h.cb({ eventType: 'INSERT', new: row }); });
            } else if (h.spec.table === 'documents') {
              var d = await new QB('documents').select('updated_at').order('updated_at', { ascending: false }).limit(1);
              var cur = (d.data && d.data[0] && d.data[0].updated_at) || '';
              if (cur !== h.last) { h.last = cur; h.cb({ eventType: 'UPDATE' }); }
            }
          } catch (_) { /* شبکه قطع: دور بعد */ }
        }
      }
      prime().then(function () {
        if (!self.alive) return;
        (function loop() {
          if (!self.alive) return;
          var wait = document.hidden ? 30000 : 7000;
          self.timer = setTimeout(function () { tick().then(loop, loop); }, wait);
        })();
      });
      return this;
    };
    Channel.prototype.stop = function () { this.alive = false; clearTimeout(this.timer); };

    var channels = [];
    return {
      from: function (t) { return new QB(t); },
      rpc: function (n, a) { return new RpcQ(n, a); },
      auth: auth,
      storage: { from: bucket },
      functions: functions,
      channel: function (n) { var c = new Channel(n); channels.push(c); return c; },
      removeChannel: function (c) { if (c && c.stop) c.stop(); channels = channels.filter(function (x) { return x !== c; }); return Promise.resolve('ok'); },
      removeAllChannels: function () { channels.forEach(function (c) { c.stop(); }); channels = []; return Promise.resolve([]); }
    };
  }

  window.supabase = { createClient: createClient };
})();
