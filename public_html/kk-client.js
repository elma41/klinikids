/* =====================================================================
   Klinikids — supabase-js yerine geçen küçük istemci
   Yönetim paneli aynı komutları kullanmaya devam eder (sb.from(...).select()...),
   ama istekler Supabase yerine kendi sunucumuzdaki /api adresine gider.
   Oturum, tarayıcının JavaScript ile okuyamadığı güvenli bir çerezde tutulur.
   ===================================================================== */
(function () {
  'use strict';
  const BASE = '/api';
  const H = { 'X-KK': '1' };

  async function call(method, url, body, extraHeaders) {
    let res;
    try {
      res = await fetch(BASE + url, {
        method, credentials: 'same-origin', cache: 'no-store',
        headers: { ...H, ...(body !== undefined && !(body instanceof Blob) ? { 'Content-Type': 'application/json' } : {}), ...(extraHeaders || {}) },
        body: body === undefined ? undefined : (body instanceof Blob ? body : JSON.stringify(body)),
      });
    } catch (e) {
      return { data: null, error: { message: 'Failed to fetch', code: 'network' }, status: 0 };
    }
    const text = await res.text();
    let json = null;
    if (text) { try { json = JSON.parse(text); } catch (_) { json = null; } }
    if (!res.ok) {
      const err = { message: (json && (json.message || json.error)) || ('HTTP ' + res.status), code: json && json.code, status: res.status,
                    context: { json: async () => json, status: res.status } };
      if (res.status === 401) emit('SIGNED_OUT');
      return { data: null, error: err, status: res.status, headers: res.headers };
    }
    return { data: json, error: null, status: res.status, headers: res.headers };
  }

  // ---------------- Sorgu kurucu (PostgREST biçimi) ----------------
  const enc = v => encodeURIComponent(v === true ? 'true' : v === false ? 'false' : v === null ? 'null' : String(v));
  class Query {
    constructor(table) { this.t = table; this.m = 'GET'; this.f = []; this.o = []; this.sel = null; this.lim = null; this.off = null; this.cnt = false; this.head = false; this.body = undefined; this.one = null; }
    select(cols, opts) {
      this.sel = (cols || '*').replace(/\s+/g, '');
      if (opts && opts.count) this.cnt = true;
      if (opts && opts.head) this.head = true;
      return this;
    }
    insert(v) { this.m = 'POST'; this.body = v; return this; }
    update(v) { this.m = 'PATCH'; this.body = v; return this; }
    delete() { this.m = 'DELETE'; return this; }
    _f(c, op, v) { this.f.push(enc(c) + '=' + op + '.' + enc(v)); return this; }
    eq(c, v) { return this._f(c, 'eq', v); }
    neq(c, v) { return this._f(c, 'neq', v); }
    gt(c, v) { return this._f(c, 'gt', v); }
    gte(c, v) { return this._f(c, 'gte', v); }
    lt(c, v) { return this._f(c, 'lt', v); }
    lte(c, v) { return this._f(c, 'lte', v); }
    like(c, v) { return this._f(c, 'like', v); }
    ilike(c, v) { return this._f(c, 'ilike', v); }
    is(c, v) { return this._f(c, 'is', v); }
    in(c, arr) { this.f.push(enc(c) + '=in.(' + arr.map(x => '"' + String(x).replace(/"/g, '\\"') + '"').map(encodeURIComponent).join(',') + ')'); return this; }
    match(obj) { Object.keys(obj).forEach(k => this.eq(k, obj[k])); return this; }
    order(c, opts) { this.o.push(c + '.' + (opts && opts.ascending === false ? 'desc' : 'asc') + (opts && opts.nullsFirst ? '.nullsfirst' : '')); return this; }
    limit(n) { this.lim = n; return this; }
    range(a, b) { this.off = a; this.lim = b - a + 1; return this; }
    single() { this.one = 'single'; return this; }
    maybeSingle() { this.one = 'maybe'; return this; }
    url() {
      const p = [];
      if (this.sel !== null) p.push('select=' + encodeURIComponent(this.sel));
      p.push(...this.f);
      if (this.o.length) p.push('order=' + this.o.join(','));
      if (this.lim !== null) p.push('limit=' + this.lim);
      if (this.off !== null) p.push('offset=' + this.off);
      if (this.cnt) p.push('count=exact');
      if (this.head) p.push('head=true');
      return '/rest/v1/' + this.t + (p.length ? '?' + p.join('&') : '');
    }
    async run() {
      const r = await call(this.m, this.url(), this.body);
      if (r.error) return { data: null, error: r.error, count: null };
      let count = null;
      const cr = r.headers && r.headers.get('X-Total-Count');
      if (cr !== null && cr !== undefined) count = Number(cr);
      let data = this.head ? null : r.data;
      if (this.m !== 'GET' && this.sel === null) data = null;
      if (this.one && Array.isArray(data)) {
        if (data.length > 1 || (this.one === 'single' && data.length === 0))
          return { data: null, error: { message: 'JSON object requested, multiple (or no) rows returned', code: 'PGRST116' }, count };
        data = data[0] || null;
      }
      return { data, error: null, count };
    }
    then(res, rej) { return this.run().then(res, rej); }
  }

  // ---------------- Oturum ----------------
  let listeners = [];
  let currentUser = null;
  function emit(ev) { listeners.forEach(cb => { try { cb(ev, ev === 'SIGNED_OUT' ? null : { user: currentUser }); } catch (_) {} }); }

  const auth = {
    async signInWithPassword({ email, password }) {
      const r = await call('POST', '/auth/login', { email, password });
      if (r.error) return { data: { user: null, session: null }, error: r.error };
      currentUser = r.data.user; emit('SIGNED_IN');
      return { data: { user: currentUser, session: { user: currentUser } }, error: null };
    },
    async signOut() { await call('POST', '/auth/logout', {}); currentUser = null; emit('SIGNED_OUT'); return { error: null }; },
    async getSession() {
      const r = await call('GET', '/auth/session');
      currentUser = r.data && r.data.user || null;
      return { data: { session: currentUser ? { user: currentUser } : null }, error: r.error };
    },
    async getUser() { const s = await auth.getSession(); return { data: { user: currentUser }, error: s.error }; },
    async updateUser(attrs) {
      const r = await call('POST', '/auth/update', attrs || {});
      if (r.error) return { data: { user: null }, error: r.error };
      currentUser = r.data.user; emit('USER_UPDATED');
      return { data: { user: currentUser }, error: null };
    },
    onAuthStateChange(cb) { listeners.push(cb); return { data: { subscription: { unsubscribe() { listeners = listeners.filter(x => x !== cb); } } } }; },
  };

  // ---------------- Dosyalar ----------------
  const pathEnc = p => String(p).split('/').map(encodeURIComponent).join('/');
  const storage = {
    from(bucket) {
      return {
        async upload(path, file, opts) {
          const r = await call('POST', '/storage/v1/object/' + bucket + '/' + pathEnc(path), file instanceof Blob ? file : new Blob([file]),
                               { 'Content-Type': (opts && opts.contentType) || (file && file.type) || 'application/octet-stream' });
          return r.error ? { data: null, error: r.error } : { data: { path, fullPath: bucket + '/' + path }, error: null };
        },
        async remove(paths) {
          const r = await call('DELETE', '/storage/v1/object/' + bucket, { prefixes: paths });
          return { data: r.data, error: r.error };
        },
        getPublicUrl(path) { return { data: { publicUrl: location.origin + '/uploads/' + bucket + '/' + pathEnc(path) } }; },
      };
    },
  };

  const functions = {
    async invoke(name, { body } = {}) {
      const r = await call('POST', '/functions/v1/' + name, body || {});
      return { data: r.data, error: r.error };
    },
  };

  function createClient() {
    return {
      from: t => new Query(t),
      rpc: async (fn, args) => {
        const r = await call('POST', '/rest/v1/rpc/' + fn, args || {});
        return { data: r.data, error: r.error };
      },
      auth, storage, functions,
    };
  }

  window.supabase = { createClient };
  window.KK_API = BASE;
})();
