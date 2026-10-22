import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { setTimeout as delay } from "node:timers/promises";
import { expectStatus as ok, key } from "../support/environment.mjs";
const future = (hours = 4) =>
  new Date(Date.now() + hours * 3600000).toISOString();
export function deadlineTests(get) {
  describe("poll deadlines", () => {
    it("requires a valid future instant and retains its UTC value after a fresh session", async () => {
      const { owner } = get();
      const base = {
        title: "Deadline validation",
        detail: "",
        options: ["A", "B"],
        requestKey: key(),
      };
      for (const closesAt of [
        undefined,
        "tomorrow",
        "2027-02-30T12:00:00Z",
        new Date(Date.now() - 1000).toISOString(),
      ]) {
        ok(await owner.request("/polls/add", { ...base, closesAt }), 422);
      }
      const closesAt = future();
      const poll = ok(
        await owner.request("/polls/add", { ...base, closesAt }),
        200,
      ).polls.find((p) => p.title === base.title);
      const fresh = await get().client().login("owner");
      const saved = ok(await fresh.request("/polls"), 200).polls.find(
        (p) => p.id === poll.id,
      );
      assert.equal(saved.closesAt, closesAt);
      assert.equal(saved.closed, false);
      assert.equal(saved.createdBy.id, get().users.owner.id);
    });
    it("rejects late voting, changes and withdrawal; reopening requires a future deadline and keeps votes", async () => {
      const { owner, member, admin, users } = get();
      const closesAt = new Date(Date.now() + 2000).toISOString();
      const draft = {
        title: "Deadline expiry",
        detail: "",
        options: ["A", "B"],
        closesAt,
        requestKey: key(),
      };
      const poll = ok(await owner.request("/polls/add", draft), 200).polls.find(
        (p) => p.title === draft.title,
      );
      const voted = ok(
        await member.request("/polls/vote", {
          id: poll.id,
          version: poll.version,
          voteVersion: 0,
          optionId: poll.options[0].id,
        }),
        200,
      ).polls.find((p) => p.id === poll.id);
      await delay(Math.max(0, Date.parse(closesAt) - Date.now() + 50));
      const expired = ok(await owner.request("/polls"), 200).polls.find(
        (p) => p.id === poll.id,
      );
      assert.equal(expired.closed, true);
      assert.equal(expired.totalVotes, 1);
      for (const optionId of [null, poll.options[1].id]) {
        const result = await member.request("/polls/vote", {
          id: poll.id,
          version: poll.version,
          voteVersion: voted.voteVersion,
          optionId,
        });
        assert.equal(result.status, 409);
        assert.equal(result.data.code, "poll_closed");
      }
      ok(
        await owner.request("/polls/vote", {
          id: poll.id,
          version: poll.version,
          voteVersion: 0,
          optionId: poll.options[1].id,
        }),
        409,
      );
      // A retry must not duplicate a saved poll even after expiry.
      assert.equal(
        ok(await owner.request("/polls/add", draft), 200).polls.filter(
          (p) => p.id === poll.id,
        ).length,
        1,
      );
      const edit = {
        id: poll.id,
        version: poll.version,
        title: poll.title,
        detail: "",
        closesAt,
        reopen: true,
      };
      ok(await owner.request("/polls/edit", edit), 422);
      const next = future(1);
      ok(await member.request("/polls/edit", { ...edit, closesAt: next }), 403);
      const reopened = ok(
        await admin.request("/polls/edit", { ...edit, closesAt: next }),
        200,
      ).polls.find((p) => p.id === poll.id);
      assert.equal(reopened.closed, false);
      assert.equal(reopened.totalVotes, 1);
      const audit = ok(
        await admin.request("/admin/activity?days=0&type=poll"),
        200,
      ).entries.find((e) => e.entityId === poll.id && e.action === "reopened");
      assert.equal(audit.actor.id, users.admin.id);
      assert.equal(audit.before.closesAt, closesAt);
      assert.equal(audit.after.closesAt, next);
      assert.equal(audit.before.closed, true);
      assert.equal(audit.after.closed, false);
      ok(
        await member.request("/polls/vote", {
          id: poll.id,
          version: reopened.version,
          voteVersion: voted.voteVersion,
          optionId: poll.options[1].id,
        }),
        200,
      );
      ok(
        await owner.request("/polls/edit", { ...edit, closesAt: future() }),
        409,
      );
    });
    it("deadline edits preserve manual closure, and a no-op edit creates no false audit revision", async () => {
      const { owner } = get();
      const draft = {
        title: "Deadline closed edits",
        detail: "",
        options: ["A", "B"],
        closesAt: future(),
        requestKey: key(),
      };
      const poll = ok(await owner.request("/polls/add", draft), 200).polls.find(
        (p) => p.title === draft.title,
      );
      const unchanged = ok(
        await owner.request("/polls/edit", {
          ...draft,
          id: poll.id,
          version: poll.version,
        }),
        200,
      ).polls.find((p) => p.id === poll.id);
      assert.equal(unchanged.version, poll.version);
      const closed = ok(
        await owner.request("/polls/close", {
          id: poll.id,
          version: poll.version,
          closed: true,
        }),
        200,
      ).polls.find((p) => p.id === poll.id);
      const edited = ok(
        await owner.request("/polls/edit", {
          ...draft,
          closesAt: future(24),
          id: poll.id,
          version: closed.version,
        }),
        200,
      ).polls.find((p) => p.id === poll.id);
      assert.equal(edited.closed, true);
    });
  });
}
