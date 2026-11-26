// bun test images/realtime  (needs a Redis at REDIS_HOST:REDIS_PORT with REDIS_PASSWORD; CI starts one)
import { afterAll, beforeAll, describe, expect, test } from "bun:test";
import { RedisClient } from "bun";

const SECRET = "test-secret-0123456789abcdef0123456789abcdef";
process.env.FLX_REALTIME_SECRET = SECRET;
process.env.PORT = "0";
process.env.RT_CHANNEL = "flx:rt:test" + Date.now();
process.env.RT_PER_USER = "2";

const { start, signTicket, verifyTicket, routes, CLOSE, config } = await import("./server.ts");

const now = () => Math.floor(Date.now() / 1000);
const jti = () => crypto.randomUUID().replaceAll("-", "");
const ticket = (uid: number, caps: string[] = [], exp = now() + 60) => signTicket({ uid, caps, exp, jti: jti() }, SECRET);
const visitorTicket = (topics: string[], exp = now() + 60) => signTicket({ uid: 0, caps: ["manage_options"], topics, exp, jti: jti() }, SECRET);

describe("visitor ticket", () => {
  test("needs topics, drops bad ones, never carries capabilities", () => {
    const t = verifyTicket(visitorTicket(["chatbot.conv.42", "Bad Topic", "public.x"]), SECRET) as { uid: number; caps: string[]; topics: string[] };
    expect(t.uid).toBe(0);
    expect(t.caps).toEqual([]);
    expect(t.topics).toEqual(["chatbot.conv.42", "public.x"]);
    expect(verifyTicket(visitorTicket([]), SECRET)).toBe("format");
    expect(verifyTicket(visitorTicket(["Bad Topic"]), SECRET)).toBe("format");
  });
  test("routes by topic after users and cap", () => {
    expect(routes({ aud: { topic: "chatbot.conv.42" } })).toEqual(["t:chatbot.conv.42"]);
    expect(routes({ aud: { topic: "no spaces" } })).toEqual([]);
    expect(routes({ aud: { users: [3], topic: "a.b" } })).toEqual(["u:3"]);
  });
});

describe("ticket", () => {
  test("valid round trip", () => {
    const t = verifyTicket(ticket(5, ["manage_options"]), SECRET);
    expect(typeof t).toBe("object");
    expect((t as { uid: number }).uid).toBe(5);
  });
  test("wrong secret, expired, too long-lived, garbage", () => {
    expect(verifyTicket(ticket(5), "other-secret-0123456789abcdef0123456789")).toBe("signature");
    expect(verifyTicket(ticket(5, [], now() - 1), SECRET)).toBe("expired");
    expect(verifyTicket(ticket(5, [], now() + 3600), SECRET)).toBe("format");
    expect(verifyTicket("v1.x.y", SECRET)).toBe("signature");
    expect(verifyTicket(123, SECRET)).toBe("format");
  });
  test("bad caps are dropped", () => {
    const t = verifyTicket(signTicket({ uid: 1, caps: ["ok_cap", "Bad Cap", "x".repeat(80)], exp: now() + 60, jti: jti() }, SECRET), SECRET);
    expect((t as { caps: string[] }).caps).toEqual(["ok_cap"]);
  });
});

describe("routes", () => {
  test("users win over cap, no audience drops", () => {
    expect(routes({ aud: { users: [1, 2, "x", -1] } })).toEqual(["u:1", "u:2"]);
    expect(routes({ aud: { cap: "manage_options" } })).toEqual(["cap:manage_options"]);
    expect(routes({ aud: { cap: "no spaces" } })).toEqual([]);
    expect(routes({})).toEqual([]);
  });
});

describe("server", () => {
  let server: Awaited<ReturnType<typeof start>>;
  let pub: RedisClient;
  let base: string;

  beforeAll(async () => {
    server = await start();
    base = `127.0.0.1:${server.port}`;
    pub = new RedisClient(config.redisUrl);
    await Bun.sleep(100);
  });
  afterAll(() => {
    server.stop(true);
    pub.close();
  });

  type Client = { ws: WebSocket; msgs: unknown[]; closed: Promise<number> };
  const connect = (path = "/flx-rt", headers: Record<string, string> = {}): Promise<Client> =>
    new Promise((resolve, reject) => {
      // @ts-expect-error Bun accepts headers here.
      const ws = new WebSocket(`ws://${base}${path}`, { headers });
      const msgs: unknown[] = [];
      let done: (c: number) => void;
      const closed = new Promise<number>((r) => (done = r));
      ws.onmessage = (e) => msgs.push(JSON.parse(String(e.data)));
      ws.onclose = (e) => done(e.code);
      ws.onopen = () => resolve({ ws, msgs, closed });
      ws.onerror = () => reject(new Error("ws error"));
    });
  const until = async (fn: () => boolean, ms = 2000) => {
    const end = Date.now() + ms;
    while (!fn() && Date.now() < end) await Bun.sleep(20);
    return fn();
  };
  const publish = (m: object) => pub.publish(config.channel, JSON.stringify(m));

  test("health", async () => {
    const r = await fetch(`http://${base}/healthz`);
    expect(r.status).toBe(200);
    expect((await r.json()).redis).toBe(true);
  });

  test("auth, cap and user delivery, aud stripped, others filtered", async () => {
    const admin = await connect("/flx-rt");
    const editor = await connect("/");
    admin.ws.send(JSON.stringify({ auth: ticket(1, ["manage_options"]) }));
    editor.ws.send(JSON.stringify({ auth: ticket(2, ["edit_posts"]) }));
    expect(await until(() => admin.msgs.length === 1 && editor.msgs.length === 1)).toBe(true);
    expect(admin.msgs[0]).toEqual({ t: "hello", uid: 1 });
    expect(await pub.get(config.channel + ":online:1")).toBe("1");

    await publish({ v: 1, t: "chatbot.inbox", inv: ["chatbot"], aud: { cap: "manage_options" } });
    await publish({ v: 1, t: "user.note", aud: { users: [2] } });
    expect(await until(() => admin.msgs.length === 2 && editor.msgs.length === 2)).toBe(true);
    await Bun.sleep(100);
    expect(admin.msgs).toHaveLength(2);
    expect(editor.msgs).toHaveLength(2);
    expect(admin.msgs[1]).toEqual({ v: 1, t: "chatbot.inbox", inv: ["chatbot"] });
    expect(editor.msgs[1]).toEqual({ v: 1, t: "user.note" });
    admin.ws.close();
    editor.ws.close();
    expect(await until(() => false, 200)).toBe(false);
    expect(await pub.get(config.channel + ":online:1")).toBeNull();
  });

  test("presence follows visible sockets only", async () => {
    const a = await connect();
    const b = await connect();
    a.ws.send(JSON.stringify({ auth: ticket(11) }));
    b.ws.send(JSON.stringify({ auth: ticket(11), vis: false }));
    expect(await until(() => a.msgs.length === 1 && b.msgs.length === 1)).toBe(true);
    const key = config.channel + ":online:11";
    expect(await pub.get(key)).toBe("1");
    a.ws.send(JSON.stringify({ vis: false }));
    await Bun.sleep(150);
    expect(await pub.get(key)).toBeNull();
    b.ws.send(JSON.stringify({ vis: true }));
    await Bun.sleep(150);
    expect(await pub.get(key)).toBe("1");
    b.ws.close();
    await Bun.sleep(150);
    expect(await pub.get(key)).toBeNull();
    a.ws.close();
  });

  test("ticket is single use", async () => {
    const t = ticket(3);
    const a = await connect();
    a.ws.send(JSON.stringify({ auth: t }));
    expect(await until(() => a.msgs.length === 1)).toBe(true);
    const b = await connect();
    b.ws.send(JSON.stringify({ auth: t }));
    expect(await b.closed).toBe(CLOSE.badTicket);
    a.ws.close();
  });

  test("bad ticket and per-user limit", async () => {
    const bad = await connect();
    bad.ws.send(JSON.stringify({ auth: "v1.abc.def" }));
    expect(await bad.closed).toBe(CLOSE.badTicket);

    const c = [await connect(), await connect(), await connect()];
    for (const x of c) x.ws.send(JSON.stringify({ auth: ticket(9) }));
    expect(await c[2].closed).toBe(CLOSE.tooMany);
    c[0].ws.close();
    c[1].ws.close();
  });

  test("revoke closes the user's sockets", async () => {
    const a = await connect();
    a.ws.send(JSON.stringify({ auth: ticket(7) }));
    expect(await until(() => a.msgs.length === 1)).toBe(true);
    await publish({ v: 1, t: "auth.revoke", aud: { users: [7] } });
    expect(await a.closed).toBe(CLOSE.revoked);
  });

  test("foreign origin is refused, app origin allowed", async () => {
    await expect(connect("/flx-rt", { Origin: "https://evil.example" })).rejects.toThrow();
    const ok = await connect("/flx-rt", { Origin: "tauri://localhost" });
    ok.ws.close();
  });

  test("visitor hears only its topic, never user or cap signals", async () => {
    const a = await connect("/flx-rt");
    const b = await connect("/flx-rt");
    const admin = await connect("/flx-rt");
    a.ws.send(JSON.stringify({ auth: visitorTicket(["chatbot.conv.1"]) }));
    b.ws.send(JSON.stringify({ auth: visitorTicket(["chatbot.conv.2", "public.counter"]) }));
    admin.ws.send(JSON.stringify({ auth: ticket(1, ["manage_options"]) }));
    expect(await until(() => a.msgs.length > 0 && b.msgs.length > 0 && admin.msgs.length > 0)).toBe(true);
    expect(a.msgs[0]).toEqual({ t: "hello", uid: 0 });
    await publish({ v: 1, t: "chatbot.conv.1", inv: [], aud: { topic: "chatbot.conv.1" } });
    await publish({ v: 1, t: "public.counter", d: { n: 7 }, aud: { topic: "public.counter" } });
    await publish({ v: 1, t: "secret.staff", aud: { cap: "manage_options" } });
    expect(await until(() => a.msgs.length === 2 && b.msgs.length === 2 && admin.msgs.length === 2)).toBe(true);
    expect((a.msgs[1] as { t: string }).t).toBe("chatbot.conv.1");
    expect((b.msgs[1] as { t: string; d: { n: number } }).d.n).toBe(7);
    expect((admin.msgs[1] as { t: string }).t).toBe("secret.staff");
    await Bun.sleep(100);
    expect(a.msgs.length).toBe(2); // Not the public counter, not the staff signal.
    expect(b.msgs.length).toBe(2);
    expect(admin.msgs.length).toBe(2); // Staff does not hear visitor topics either.
    a.ws.close();
    b.ws.close();
    admin.ws.close();
  });

  test("unknown path 404, plain GET 426", async () => {
    expect((await fetch(`http://${base}/nope`)).status).toBe(404);
    expect((await fetch(`http://${base}/flx-rt`)).status).toBe(426);
  });
});
