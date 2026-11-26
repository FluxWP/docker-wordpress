// FluxWP Realtime: WebSocket fan-out for one WordPress site.
//
// WordPress (mu-plugin flx-realtime.php) PUBLISHes small signals to Redis; this server forwards them to the
// signed-in panel / FluxApp clients that may see them. Signals say "this changed" (topic + query keys + ids);
// clients reload the data itself through WordPress, so permissions stay in WordPress.
//
// Auth: the client gets a 60 second ticket from WordPress (HMAC-SHA256 with FLX_REALTIME_SECRET) and sends it
// as the first message {"auth": "<ticket>"}. Never in the URL, so it does not reach proxy access logs.
//
// No secret configured: the server stays up and reports {"enabled": false}; WordPress keeps polling.

import { RedisClient, type ServerWebSocket } from "bun";
import { createHmac, timingSafeEqual } from "node:crypto";

const env = (k: string, d = "") => (process.env[k] ?? d).trim();
const int = (k: string, d: number, min: number, max: number) => {
  const n = Number.parseInt(env(k), 10);
  return Number.isFinite(n) && n >= min && n <= max ? n : d;
};

export const config = {
  port: int("PORT", 80, 0, 65535),
  secret: env("FLX_REALTIME_SECRET"),
  channel: env("RT_CHANNEL", "flx:rt"),
  redisUrl: (() => {
    const host = env("REDIS_HOST", "redis");
    const port = int("REDIS_PORT", 6379, 1, 65535);
    const pw = env("REDIS_PASSWORD");
    return `redis://${pw ? ":" + encodeURIComponent(pw) + "@" : ""}${host}:${port}`;
  })(),
  maxConn: int("RT_MAX_CONN", 500, 1, 100000),
  perUser: int("RT_PER_USER", 10, 1, 1000),
  /** Visitor (signed-out) sockets at once, e.g. site chat widgets and public counters. */
  maxVisitors: int("RT_MAX_VISITORS", 300, 0, 100000),
  visitorLifeMs: int("RT_VISITOR_LIFE_MINUTES", 60, 1, 1440) * 60_000,
  perIpPerMin: int("RT_PER_IP_PER_MIN", 30, 1, 10000),
  authTimeoutMs: 5000,
  maxLifeMs: int("RT_MAX_LIFE_HOURS", 12, 1, 168) * 3600_000,
  maxMessage: 8192,
  origins: env("RT_ORIGINS").split(",").map((s) => s.trim()).filter(Boolean),
};

export const CLOSE = { authTimeout: 4000, expired: 4001, badTicket: 4003, tooMany: 4008, revoked: 4009 } as const;

// Desktop app (Tauri) origins; native mobile (Expo) sends no Origin header at all.
const APP_ORIGINS = new Set(["tauri://localhost", "http://tauri.localhost", "https://tauri.localhost"]);

export interface Ticket {
  /** 0 = visitor ticket (no WordPress user): it carries `topics` and nothing else. */
  uid: number;
  caps: string[];
  /** Visitor topics this socket may hear, e.g. "chatbot.conv.42" or "public.donate.total". */
  topics: string[];
  exp: number;
  jti: string;
}

const TOPIC_RE = /^[a-z0-9_.:-]{1,80}$/;

const b64url = (b: Buffer) => b.toString("base64url");

/** Verifies "v1.<payload>.<sig>". Returns the ticket or a reason. Exported for tests. */
export function verifyTicket(raw: unknown, secret: string, now = Math.floor(Date.now() / 1000)): Ticket | string {
  if (typeof raw !== "string" || raw.length > 4096) return "format";
  const parts = raw.split(".");
  if (parts.length !== 3 || parts[0] !== "v1") return "format";
  const expected = createHmac("sha256", secret).update(parts[0] + "." + parts[1]).digest();
  let given: Buffer;
  try {
    given = Buffer.from(parts[2], "base64url");
  } catch {
    return "signature";
  }
  if (given.length !== expected.length || !timingSafeEqual(given, expected)) return "signature";
  let t: Partial<Ticket>;
  try {
    t = JSON.parse(Buffer.from(parts[1], "base64url").toString("utf8"));
  } catch {
    return "format";
  }
  const topics = Array.isArray(t.topics) ? t.topics.filter((x) => typeof x === "string" && TOPIC_RE.test(x)).slice(0, 5) : [];
  // A user ticket needs a user id; a visitor ticket (uid 0) needs at least one topic and carries no capabilities.
  if (!Number.isInteger(t.uid) || (t.uid as number) < 0) return "format";
  if ((t.uid as number) === 0 && !topics.length) return "format";
  if (!Number.isInteger(t.exp) || (t.exp as number) < now) return "expired";
  if ((t.exp as number) > now + 300) return "format"; // Tickets are short-lived by design.
  if (typeof t.jti !== "string" || !/^[A-Za-z0-9_-]{8,64}$/.test(t.jti)) return "format";
  const caps = Array.isArray(t.caps) ? t.caps.filter((c) => typeof c === "string" && /^[a-z0-9_]{1,64}$/.test(c)) : [];
  return { uid: t.uid as number, caps: t.uid === 0 ? [] : caps, topics, exp: t.exp as number, jti: t.jti };
}

/** Same format WordPress writes; used by tests and `bun server.ts --ticket`. */
export function signTicket(t: Omit<Ticket, "topics"> & { topics?: string[] }, secret: string): string {
  const body = b64url(Buffer.from(JSON.stringify(t)));
  return "v1." + body + "." + b64url(createHmac("sha256", secret).update("v1." + body).digest());
}

interface Envelope {
  v?: number;
  t?: string;
  aud?: { users?: unknown; cap?: unknown; topic?: unknown };
  [k: string]: unknown;
}

/** Bun pub/sub topics a message goes to; [] = drop. Exported for tests. */
export function routes(msg: Envelope): string[] {
  const aud = msg.aud ?? {};
  const users = Array.isArray(aud.users) ? aud.users.filter((u) => Number.isInteger(u) && (u as number) > 0) : [];
  if (users.length) return users.map((u) => "u:" + u);
  if (typeof aud.cap === "string" && /^[a-z0-9_]{1,64}$/.test(aud.cap)) return ["cap:" + aud.cap];
  if (typeof aud.topic === "string" && TOPIC_RE.test(aud.topic)) return ["t:" + aud.topic];
  return []; // No audience: nobody. WordPress always sets one (default manage_options).
}

interface Conn {
  ip: string;
  uid: number;
  /** Screen in front (client sends {"vis": false} when its tab/app goes to the background). */
  visible: boolean;
  authTimer?: ReturnType<typeof setTimeout>;
  lifeTimer?: ReturnType<typeof setTimeout>;
}

const stats = { received: 0, delivered: 0, dropped: 0, lastAt: 0, startedAt: Date.now() };
const byUser = new Map<number, Set<ServerWebSocket<Conn>>>();
const attempts = new Map<string, number[]>();
let open = 0;
let visitors = 0;

function rateOk(ip: string): boolean {
  const now = Date.now();
  const list = (attempts.get(ip) ?? []).filter((t) => now - t < 60_000);
  list.push(now);
  attempts.set(ip, list);
  if (attempts.size > 10_000) attempts.clear(); // Bounded memory under a flood.
  return list.length <= config.perIpPerMin;
}

function originOk(req: Request): boolean {
  const origin = req.headers.get("origin");
  if (!origin) return true; // Native apps, server-side bridges (TV Remote): the ticket is the gate.
  if (APP_ORIGINS.has(origin) || config.origins.includes(origin)) return true;
  try {
    const host = req.headers.get("x-forwarded-host") ?? req.headers.get("host") ?? "";
    return new URL(origin).host === host.split(",")[0].trim();
  } catch {
    return false;
  }
}

function clientIp(req: Request, server: { requestIP(r: Request): { address: string } | null }): string {
  // Behind Traefik / nginx the first X-Forwarded-For hop is the visitor.
  const xff = req.headers.get("x-forwarded-for");
  if (xff) return xff.split(",")[0].trim();
  return server.requestIP(req)?.address ?? "?";
}

// Presence for WordPress: "flx:rt:online:<uid>" exists while the user has a socket open on a screen in front
// (TTL refreshed), so a phone push can be skipped when the message already reached a screen they are looking at.
// A tab left open in the background does not count.
const ONLINE_TTL = 90;
let presence: RedisClient | null = null;
const onlineKey = (uid: number) => `${config.channel}:online:${uid}`;
const seen = (uid: number) => [...(byUser.get(uid) ?? [])].some((w) => w.data.visible);
function markOnline(uid: number) {
  if (!presence) return;
  (seen(uid) ? presence.set(onlineKey(uid), "1", "EX", String(ONLINE_TTL)) : presence.del(onlineKey(uid))).catch(() => null);
}

function drop(ws: ServerWebSocket<Conn>) {
  const set = byUser.get(ws.data.uid);
  if (set) {
    set.delete(ws);
    if (!set.size) byUser.delete(ws.data.uid);
    markOnline(ws.data.uid);
  }
}

export async function start() {
  const enabled = config.secret.length >= 32;
  let sub: RedisClient | null = null;
  let cmd: RedisClient | null = null;

  const server = Bun.serve<Conn, undefined>({
    port: config.port,
    fetch(req, srv) {
      const url = new URL(req.url);
      const path = url.pathname.replace(/\/+$/, "");
      if (path === "/healthz" || path === "/flx-rt/healthz") {
        const body = {
          enabled,
          redis: enabled ? Boolean(sub?.connected) : false,
          connections: open,
          users: byUser.size,
          visitors,
          ...stats,
        };
        return Response.json(body, { status: !enabled || body.redis ? 200 : 503 });
      }
      // Coolify may or may not strip the /flx-rt prefix; accept both.
      if (path !== "" && path !== "/flx-rt") return new Response("Not found", { status: 404 });
      if (!enabled) return new Response("Realtime is not configured", { status: 503 });
      if (req.headers.get("upgrade")?.toLowerCase() !== "websocket") return new Response("WebSocket only", { status: 426 });
      const ip = clientIp(req, srv);
      if (!originOk(req)) return new Response("Forbidden", { status: 403 });
      if (!rateOk(ip) || open >= config.maxConn) return new Response("Too many connections", { status: 429 });
      if (srv.upgrade(req, { data: { ip, uid: 0, visible: true } })) return undefined;
      return new Response("Upgrade failed", { status: 400 });
    },
    websocket: {
      maxPayloadLength: 8192,
      idleTimeout: 60, // Bun pings idle sockets; proxies (Cloudflare 100 s) stay open.
      sendPings: true,
      backpressureLimit: 256 * 1024,
      closeOnBackpressureLimit: true,
      open(ws) {
        open++;
        ws.data.authTimer = setTimeout(() => ws.close(CLOSE.authTimeout, "auth timeout"), config.authTimeoutMs);
      },
      async message(ws, raw) {
        let msg: { auth?: unknown; ping?: unknown };
        try {
          msg = JSON.parse(typeof raw === "string" ? raw : raw.toString());
        } catch {
          return;
        }
        if (ws.data.uid) {
          if (msg.ping !== undefined) ws.send('{"t":"pong"}');
          if (typeof (msg as { vis?: unknown }).vis === "boolean" && (msg as { vis: boolean }).vis !== ws.data.visible) {
            ws.data.visible = (msg as { vis: boolean }).vis;
            if (ws.data.uid > 0) markOnline(ws.data.uid);
          }
          return; // Clients only listen; nothing else is accepted.
        }
        if (msg.auth === undefined) return;
        if (typeof (msg as { vis?: unknown }).vis === "boolean") ws.data.visible = (msg as { vis: boolean }).vis;
        const t = verifyTicket(msg.auth, config.secret);
        if (typeof t === "string") return ws.close(t === "expired" ? CLOSE.expired : CLOSE.badTicket, t);
        // One use per ticket: a copied ticket cannot open a second socket.
        const fresh = await cmd?.set(`${config.channel}:jti:${t.jti}`, "1", "NX", "EX", "600").catch(() => null);
        if (fresh !== "OK") return ws.close(CLOSE.badTicket, "used");
        if (ws.readyState !== 1) return;
        if (t.uid === 0) {
          // Visitor: only the topics WordPress signed, never users or capabilities.
          if (visitors >= config.maxVisitors) return ws.close(CLOSE.tooMany, "too many");
          visitors++;
          clearTimeout(ws.data.authTimer);
          ws.data.uid = -1;
          for (const topic of t.topics) ws.subscribe("t:" + topic);
          ws.data.lifeTimer = setTimeout(() => ws.close(CLOSE.expired, "renew"), config.visitorLifeMs);
          ws.send(JSON.stringify({ t: "hello", uid: 0 }));
          return;
        }
        const mine = byUser.get(t.uid) ?? new Set();
        if (mine.size >= config.perUser) return ws.close(CLOSE.tooMany, "too many");
        clearTimeout(ws.data.authTimer);
        ws.data.uid = t.uid;
        mine.add(ws);
        byUser.set(t.uid, mine);
        markOnline(t.uid);
        ws.subscribe("u:" + t.uid);
        for (const c of t.caps) ws.subscribe("cap:" + c);
        ws.data.lifeTimer = setTimeout(() => ws.close(CLOSE.expired, "renew"), config.maxLifeMs);
        ws.send(JSON.stringify({ t: "hello", uid: t.uid }));
      },
      close(ws) {
        open--;
        clearTimeout(ws.data.authTimer);
        clearTimeout(ws.data.lifeTimer);
        if (ws.data.uid === -1) visitors--;
        else if (ws.data.uid) drop(ws);
      },
    },
  });

  if (enabled) {
    sub = new RedisClient(config.redisUrl);
    cmd = new RedisClient(config.redisUrl);
    presence = cmd;
    setInterval(() => {
      for (const uid of byUser.keys()) markOnline(uid);
    }, 60_000).unref();
    await sub.subscribe(config.channel, (raw: string) => {
      stats.received++;
      stats.lastAt = Math.floor(Date.now() / 1000);
      if (raw.length > config.maxMessage) return void stats.dropped++;
      let msg: Envelope;
      try {
        msg = JSON.parse(raw);
      } catch {
        return void stats.dropped++;
      }
      const to = routes(msg);
      if (!to.length) return void stats.dropped++;
      if (msg.t === "auth.revoke") {
        for (const topic of to) {
          const uid = Number(topic.slice(2));
          for (const ws of byUser.get(uid) ?? []) ws.close(CLOSE.revoked, "revoked");
        }
        return;
      }
      const { aud: _aud, ...out } = msg;
      const text = JSON.stringify(out);
      for (const topic of to) if (server.publish(topic, text) > 0) stats.delivered++;
    });
    console.log(`flx-realtime: listening on :${server.port}, channel ${config.channel}`);
  } else {
    console.log("flx-realtime: FLX_REALTIME_SECRET is not set (or shorter than 32), staying idle; WordPress keeps polling");
  }
  return server;
}

if (import.meta.main) {
  if (process.argv[2] === "--ticket") {
    // Debug helper: bun server.ts --ticket <uid> [cap,cap]
    const caps = (process.argv[4] ?? "manage_options").split(",");
    const now = Math.floor(Date.now() / 1000);
    console.log(signTicket({ uid: Number(process.argv[3] ?? 1), caps, exp: now + 60, jti: crypto.randomUUID().replaceAll("-", "") }, config.secret));
  } else {
    await start();
  }
}
