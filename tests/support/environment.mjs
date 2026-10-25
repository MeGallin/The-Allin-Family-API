import assert from "node:assert/strict";
import { randomBytes } from "node:crypto";
import { spawn, execFileSync } from "node:child_process";
import { once } from "node:events";
import { readFileSync, openSync, closeSync, existsSync } from "node:fs";
import { createServer } from "node:net";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { setTimeout as delay } from "node:timers/promises";
const root = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const php =
  process.env.PHP_BINARY ||
  (process.platform === "win32" ? "C:/xampp/php/php.exe" : "php");
const fixture = (mode, runId, suite) =>
  execFileSync(
    php,
    ["tests/support/regression-fixture.php", mode, runId, suite],
    {
      cwd: root,
      stdio: "inherit",
      windowsHide: true,
    },
  );
export async function startEnvironment({ suite = "regression" } = {}) {
  assert.ok(["regression", "sudoku", "daily-word", "focus-timer"].includes(suite));
  const runId = randomBytes(24).toString("hex");
  const directory =
    suite !== "regression"
      ? `.local/${suite}-regression-${runId.slice(0, 12)}`
      : ".local/regression";
  const fixtureFile = resolve(root, directory, "fixture.json");
  let server,
    log,
    ownsFixture = false,
    stopped = false;
  const stop = async () => {
    if (stopped) return;
    stopped = true;
    if (server?.pid && server.exitCode === null && server.signalCode === null) {
      const exited = once(server, "exit");
      server.kill();
      await exited;
    }
    if (log !== undefined) closeSync(log);
    if (!ownsFixture) return;
    fixture("cleanup", runId, suite);
    assert.equal(
      existsSync(fixtureFile),
      false,
      "Disposable fixture marker must be gone",
    );
  };
  try {
    fixture("create", runId, suite);
    ownsFixture = true;
    const config = JSON.parse(readFileSync(fixtureFile, "utf8"));
    assert.equal(
      config.DB_NAME,
      suite !== "regression"
        ? `allin_${suite === "sudoku" ? "sudoku" : suite === "focus-timer" ? "focus" : "word"}_test_${runId.slice(0, 12)}`
        : "theallinfamily_regression_test",
    );
    assert.equal(config.APP_ENV, "test");
    const port = await new Promise((done, reject) => {
      const probe = createServer();
      probe.once("error", reject);
      probe.listen(0, "127.0.0.1", () => {
        const port = probe.address().port;
        probe.close(() => done(port));
      });
    });
    log = openSync(resolve(root, directory, "server.log"), "a");
    const runtime = Object.fromEntries(
      Object.entries(config).filter(([key]) => !key.startsWith("TEST_")),
    );
    server = spawn(
      php,
      [
        "-d",
        "extension=gd",
        "-d",
        "memory_limit=512M",
        "-S",
        `127.0.0.1:${port}`,
        "router.php",
      ],
      {
        cwd: root,
        env: { ...process.env, ...runtime },
        stdio: ["ignore", log, log],
        windowsHide: true,
      },
    );
    let spawnError;
    server.once("error", (error) => {
      spawnError = error;
    });
    const base = `http://127.0.0.1:${port}`;
    let ready = false;
    for (let i = 0; i < 50; i++) {
      if (spawnError) throw spawnError;
      if (server.exitCode !== null)
        throw new Error(
          "Disposable API exited; inspect .local/regression/server.log",
        );
      try {
        ready = (
          await fetch(base + "/health", { signal: AbortSignal.timeout(1000) })
        ).ok;
      } catch {}
      if (ready) break;
      await delay(100);
    }
    assert.ok(ready, "Disposable API failed to start");
    class Client {
      cookie = "";
      csrf = "";
      user;
      async request(path, body, headers = {}) {
        assert.ok(
          path.startsWith("/") && !path.startsWith("//"),
          "Only this disposable API is allowed",
        );
        const multipart = body instanceof FormData;
        const response = await fetch(base + path, {
          method: body === undefined ? "GET" : "POST",
          signal: AbortSignal.timeout(10000),
          headers: {
            Cookie: this.cookie,
            Origin: config.APP_ORIGIN,
            Accept: "application/json",
            ...(body === undefined
              ? {}
              : {
                  "X-CSRF-Token": this.csrf,
                  ...(multipart ? {} : { "Content-Type": "application/json" }),
                }),
            ...headers,
          },
          body:
            body === undefined
              ? undefined
              : multipart
                ? body
                : JSON.stringify(body),
        });
        for (const cookie of response.headers.getSetCookie())
          if (cookie.startsWith("allin_family_session="))
            this.cookie = cookie.split(";")[0];
        const bytes = Buffer.from(await response.arrayBuffer());
        const data =
          bytes.length &&
          response.headers.get("content-type")?.includes("application/json")
            ? JSON.parse(bytes.toString())
            : null;
        if (data?.csrfToken) this.csrf = data.csrfToken;
        return {
          status: response.status,
          data,
          headers: response.headers,
          bytes,
        };
      }
      async login(role = "owner") {
        await this.request("/auth/session");
        const response = await this.request("/auth/login", {
          username: config.TEST_USERS[role].username,
          password: config.TEST_PASSWORD,
        });
        assert.equal(response.status, 200, "Fixture login");
        this.user = response.data.user;
        return this;
      }
    }
    const env = {
      stop,
      fixtureFile,
      runId,
      users: config.TEST_USERS,
      client: () => new Client(),
    };
    env.owner = await env.client().login("owner");
    env.member = await env.client().login("member");
    env.admin = await env.client().login("admin");
    return env;
  } catch (error) {
    // A create/migration failure may leave the marked database for explicit recovery.
    if (!ownsFixture && existsSync(fixtureFile)) {
      const marker = JSON.parse(readFileSync(fixtureFile, "utf8"));
      ownsFixture = marker.TEST_RUN_ID === runId;
    }
    await stop();
    throw error;
  }
}
export const expectStatus = (response, status) => {
  assert.equal(
    response.status,
    status,
    response.data?.error || `Expected HTTP ${status}`,
  );
  return response.data;
};
export const key = () => crypto.randomUUID();
export function memoryForm(body, photo) {
  const form = new FormData();
  form.set("payload", JSON.stringify(body));
  if (photo)
    form.set("photo", new Blob([photo], { type: "image/png" }), "fixture.png");
  return form;
}
// One-pixel local raster. Never uploads or downloads a real family's photograph.
export const png = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aV1sAAAAASUVORK5CYII=",
  "base64",
);
