import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { readFileSync } from "node:fs";
import { expectStatus as ok } from "../support/environment.mjs";
const root = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const php =
  process.env.PHP_BINARY ||
  (process.platform === "win32" ? "C:/xampp/php/php.exe" : "php");
export function isolationTests(get) {
  describe("fixture ownership guards", () => {
    it("refuses a second run and refuses cleanup with another ownership token", async () => {
      const before = readFileSync(
        resolve(root, ".local/regression/fixture.json"),
        "utf8",
      );
      for (const action of ["create", "cleanup"])
        assert.throws(() =>
          execFileSync(
            php,
            ["tests/support/regression-fixture.php", action, "f".repeat(48)],
            { cwd: root, stdio: "pipe", windowsHide: true },
          ),
        );
      assert.equal(
        readFileSync(resolve(root, ".local/regression/fixture.json"), "utf8"),
        before,
      );
      ok(await get().owner.request("/notes"), 200);
    });
  });
}
