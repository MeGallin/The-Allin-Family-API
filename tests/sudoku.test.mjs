import { before, after, describe, it } from "node:test";
import assert from "node:assert/strict";
import { execFileSync, execFile } from "node:child_process";
import { promisify } from "node:util";
import { dirname, join } from "node:path";
import { writeFileSync, rmSync } from "node:fs";
import {
  startEnvironment,
  expectStatus as ok,
  key,
} from "./support/environment.mjs";
const php = process.env.PHP_BINARY || "C:/xampp/php/php.exe",
  run = promisify(execFile);
let env;
before(async () => {
  env = await startEnvironment({ suite: "sudoku" });
});
after(async () => {
  await env?.stop();
});
const snapshot = async () => ok(await env.owner.request("/sudoku"), 200);
const post = async (action, body, status = 200) =>
  ok(await env.owner.request("/sudoku/" + action, body), status);
async function game() {
  const data = await snapshot();
  return (
    data.game ??
    (
      await post("install", {
        version: data.installationVersion,
        difficulty: "easy",
        requestKey: key(),
      })
    ).game
  );
}
const saveBody = (g, extra = {}) => ({
  id: g.id,
  version: g.version,
  entries: g.entries,
  notes: [...g.notes],
  paused: g.paused,
  requestKey: key(),
  ...extra,
});
// Independent solver: own boolean constraint sets/backtracking; no production PHP code or solution is used.
function solve(puzzle) {
  const board = [...puzzle].map(Number),
    rows = Array.from({ length: 9 }, () => new Set()),
    cols = Array.from({ length: 9 }, () => new Set()),
    boxes = Array.from({ length: 9 }, () => new Set());
  let count = 0,
    answer;
  const box = (i) => Math.floor(i / 27) * 3 + Math.floor((i % 9) / 3);
  for (let i = 0; i < 81; i++) {
    const d = board[i];
    if (!d) continue;
    const r = Math.floor(i / 9),
      c = i % 9,
      b = box(i);
    if (rows[r].has(d) || cols[c].has(d) || boxes[b].has(d))
      return { count: 0 };
    rows[r].add(d);
    cols[c].add(d);
    boxes[b].add(d);
  }
  function search() {
    if (count >= 2) return;
    let cell = -1,
      choices = [];
    for (let i = 0; i < 81; i++) {
      if (board[i]) continue;
      const possible = [1, 2, 3, 4, 5, 6, 7, 8, 9].filter(
        (d) =>
          !rows[Math.floor(i / 9)].has(d) &&
          !cols[i % 9].has(d) &&
          !boxes[box(i)].has(d),
      );
      if (!possible.length) return;
      if (cell < 0 || possible.length < choices.length) {
        cell = i;
        choices = possible;
        if (choices.length === 1) break;
      }
    }
    if (cell < 0) {
      count++;
      answer = board.join("");
      return;
    }
    const r = Math.floor(cell / 9),
      c = cell % 9,
      b = box(cell);
    for (const d of choices) {
      board[cell] = d;
      rows[r].add(d);
      cols[c].add(d);
      boxes[b].add(d);
      search();
      board[cell] = 0;
      rows[r].delete(d);
      cols[c].delete(d);
      boxes[b].delete(d);
      if (count >= 2) return;
    }
  }
  search();
  return { count, answer };
}
describe("Sudoku isolated API, independent uniqueness and concurrency", () => {
  it("bounds proof search and rejects invalid completion in pure module checks", () => {
    const result = JSON.parse(
      execFileSync(php, ["tests/sudoku-unit.php"], {
        encoding: "utf8",
        windowsHide: true,
      }),
    );
    assert.equal(result.passed, 5);
  });
  for (const [difficulty, clues] of [
    ["easy", 42],
    ["medium", 36],
    ["hard", 30],
  ])
    it(`generates two distinct valid unique ${difficulty} puzzles with ${clues} clues`, async () => {
      const puzzles = new Set();
      for (let n = 0; n < 2; n++) {
        const g = await game();
        const next = (
          await post("new", {
            id: g.id,
            version: g.version,
            difficulty,
            requestKey: key(),
          })
        ).game;
        assert.equal(next.givens.replaceAll("0", "").length, clues);
        assert.equal(next.entries, next.givens);
        assert.equal(next.difficulty, difficulty);
        assert.equal(solve(next.givens).count, 1);
        puzzles.add(next.givens);
      }
      assert.equal(puzzles.size, 2);
    });
  it("requires session/CSRF and isolates members/admins from the owner game", async () => {
    const g = await game();
    const anonymous = env.client();
    ok(await anonymous.request("/sudoku"), 401);
    ok(
      await env.owner.request("/sudoku/save", saveBody(g), {
        "X-CSRF-Token": "",
      }),
      403,
    );
    for (const actor of [env.member, env.admin]) {
      const other = ok(
        await actor.request("/sudoku?ownerId=" + env.owner.user.id),
        200,
      );
      assert.equal(other.game, null);
      ok(await actor.request("/sudoku/save", saveBody(g)), 404);
      ok(
        await actor.request("/sudoku/remove", {
          id: g.id,
          version: g.version,
          requestKey: key(),
        }),
        404,
      );
    }
    const member = ok(
      await env.member.request("/sudoku/install", {
        version: 0,
        difficulty: "easy",
        ownerId: env.owner.user.id,
        requestKey: key(),
      }),
      200,
    );
    assert.equal(member.ownerId, env.member.user.id);
    assert.notEqual(member.game.id, g.id);
    assert.equal((await snapshot()).game.id, g.id);
    assert.equal(
      ok(await env.owner.request("/widgets"), 200).widgets.some(
        (w) => w.kind === "sudoku",
      ),
      false,
    );
  });
  it("validates immutable clues and all entry/note types without rejecting saved mistakes", async () => {
    let g = await game();
    const given = g.givens.search(/[1-9]/),
      empty = g.givens.indexOf("0");
    const entries = g.entries.split("");
    entries[given] = "0";
    const malformed = [
      { entries: entries.join("") },
      { entries: g.entries.slice(1) },
      { entries: g.entries.replace("0", "x") },
      { notes: Array(80).fill(0) },
      { notes: Array(81).fill(512) },
      { notes: Array(81).fill(-1) },
      { notes: Array(81).fill("1") },
      { notes: Array(81).fill(0.1) },
      { paused: 1 },
    ];
    for (const extra of malformed) {
      await post("save", saveBody(g, extra), 422);
      assert.equal((await snapshot()).game.version, g.version);
    }
    const onGiven = [...g.notes];
    onGiven[given] = 1;
    await post("save", saveBody(g, { notes: onGiven }), 422);
    const wrong = g.entries.split("");
    wrong[empty] = g.givens[given];
    const notes = [...g.notes];
    const otherEmpty = g.givens.indexOf("0", empty + 1);
    notes[otherEmpty] = 257;
    g = (
      await post(
        "save",
        saveBody(g, { entries: wrong.join(""), notes, paused: true }),
      )
    ).game;
    assert.equal(g.completedAt, null);
    assert.equal(g.entries[empty], wrong[empty]);
    assert.equal(g.notes[otherEmpty], 257);
    assert.equal(g.paused, true);
    assert.deepEqual((await snapshot()).game, g);
    const invalid = solve(g.givens).answer.split("");
    invalid[empty] = String((Number(invalid[empty]) % 9) + 1);
    g = (
      await post(
        "save",
        saveBody(g, {
          entries: invalid.join(""),
          notes: Array(81).fill(0),
          paused: false,
        }),
      )
    ).game;
    assert.equal(g.completedAt, null);
  });
  it("computes completion independently, audits lifecycle only and never board/pencil data", async () => {
    let g = await game();
    const solution = solve(g.givens);
    assert.equal(solution.count, 1);
    const before = ok(
      await env.admin.request("/admin/activity?type=widget&days=0"),
      200,
    ).entries.length;
    g = (
      await post(
        "save",
        saveBody(g, {
          entries: solution.answer,
          notes: Array(81).fill(0),
          paused: false,
        }),
      )
    ).game;
    assert.ok(g.completedAt);
    const after = ok(
      await env.admin.request("/admin/activity?type=widget&days=0"),
      200,
    ).entries;
    assert.equal(after.length, before + 1);
    const complete = after[0];
    assert.equal(complete.action, "completed");
    assert.equal(complete.after.kind, "sudoku");
    for (const entry of after)
      for (const payload of [entry.before, entry.after])
        if (payload) {
          assert.ok(
            Object.keys(payload).every((k) =>
              [
                "kind",
                "ownerId",
                "visibility",
                "difficulty",
                "version",
              ].includes(k),
            ),
          );
          assert.ok(!JSON.stringify(payload).includes(g.givens));
          assert.ok(!JSON.stringify(payload).includes(solution.answer));
        }
    await post("save", saveBody(g, { paused: true }));
    assert.equal(
      ok(await env.admin.request("/admin/activity?type=widget&days=0"), 200)
        .entries.length,
      after.length,
    );
  });
  it("replays exact mutations once, rejects changed retry bodies, and keeps newer games from stale writes", async () => {
    let g = await game();
    const body = saveBody(g, { paused: !g.paused });
    const first = (await post("save", body)).game;
    assert.deepEqual((await post("save", body)).game, first);
    await post("save", { ...body, paused: g.paused }, 422);
    const fresh = (
      await post("new", {
        id: first.id,
        version: first.version,
        difficulty: "medium",
        requestKey: key(),
      })
    ).game;
    await post("save", saveBody(g), 409);
    await post(
      "restart",
      { id: g.id, version: g.version, requestKey: key() },
      409,
    );
    await post(
      "remove",
      { id: g.id, version: g.version, requestKey: key() },
      409,
    );
    assert.deepEqual((await snapshot()).game, fresh);
    const invalid = {
      id: fresh.id,
      version: fresh.version,
      difficulty: "unsupported",
      requestKey: key(),
    };
    await post("new", invalid, 422);
    assert.deepEqual((await snapshot()).game, fresh);
    const restart = { id: fresh.id, version: fresh.version, requestKey: key() };
    g = (await post("restart", restart)).game;
    assert.equal(g.entries, g.givens);
    assert.ok(g.notes.every((n) => n === 0));
    assert.equal(g.paused, false);
    assert.equal(g.completedAt, null);
    assert.equal((await post("restart", restart)).game.version, g.version);
  });
  it("serializes competing process saves so exactly one current-version write wins", async () => {
    const g = await game(),
      directory = dirname(env.fixtureFile);
    for (const label of ["a", "b"])
      writeFileSync(
        join(directory, `race-${label}.json`),
        JSON.stringify(saveBody(g, { paused: label === "a" })),
      );
    try {
      const responses = await Promise.all(
        ["a", "b"].map((label) =>
          run(
            php,
            [
              "tests/support/sudoku-race.php",
              env.fixtureFile,
              env.runId,
              label,
            ],
            { windowsHide: true, timeout: 15000 },
          ),
        ),
      );
      assert.deepEqual(
        responses.map((r) => JSON.parse(r.stdout).status).sort(),
        [200, 409],
      );
      assert.equal((await snapshot()).game.version, g.version + 1);
    } finally {
      for (const label of ["a", "b"])
        for (const name of [`race-${label}.json`, `ready-${label}`])
          rmSync(join(directory, name), { force: true });
    }
  });
  it("soft-removes then reinstalls with monotonic revision and rejects late old resurrection", async () => {
    const g = await game(),
      body = { id: g.id, version: g.version, requestKey: key() };
    const removed = await post("remove", body);
    assert.equal(removed.game, null);
    assert.equal(
      (await post("remove", body)).installationVersion,
      removed.installationVersion,
    );
    await post(
      "install",
      { version: 0, difficulty: "easy", requestKey: key() },
      409,
    );
    await post("save", saveBody(g), 404);
    const install = {
      version: removed.installationVersion,
      difficulty: "hard",
      requestKey: key(),
    };
    const next = (await post("install", install)).game;
    assert.equal(next.id, g.id);
    assert.ok(next.version > g.version);
    assert.equal(next.entries, next.givens);
    assert.equal(next.givens.replaceAll("0", "").length, 30);
    assert.equal((await post("install", install)).game.version, next.version);
    await post("save", saveBody(g), 409);
    assert.deepEqual((await snapshot()).game, next);
  });
});
