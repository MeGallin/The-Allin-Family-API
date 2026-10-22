import { describe, it } from "node:test";
import assert from "node:assert/strict";
import {
  expectStatus as ok,
  key,
  memoryForm,
} from "../support/environment.mjs";
export function permissionsTests(get) {
  describe("server permissions and write validation", () => {
    it("members cannot access admin reports, directory or account changes", async () => {
      const { member } = get();
      for (const path of [
        "/admin/activity",
        "/admin/usage",
        "/admin/insights",
        "/admin/members",
      ])
        ok(await member.request(path), 403);
      for (const path of [
        "/admin/members/add",
        "/admin/members/edit",
        "/admin/members/password",
      ])
        ok(await member.request(path, { id: 999, role: "admin" }), 403);
      assert.equal(
        ok(await member.request("/profile"), 200).profile.role,
        "member",
      );
    });
    it("cross-site and missing-CSRF writes cannot change saved content", async () => {
      const { owner } = get();
      const body = { title: "Rejected regression write", requestKey: key() };
      ok(
        await owner.request("/shopping/add", body, { "X-CSRF-Token": "" }),
        403,
      );
      ok(
        await owner.request("/shopping/add", body, {
          Origin: "https://other.invalid",
        }),
        403,
      );
      assert.equal(
        ok(await owner.request("/shopping"), 200).items.some(
          (x) => x.title === body.title,
        ),
        false,
      );
    });
    it("invalid forms cannot create partial records", async () => {
      const { owner } = get();
      ok(
        await owner.request("/shopping/add", { title: "", requestKey: key() }),
        422,
      );
      ok(
        await owner.request("/notes/add", {
          title: "No body",
          body: "",
          noteDate: "2026-10-22",
          requestKey: key(),
        }),
        422,
      );
      ok(
        await owner.request("/polls/add", {
          title: "Duplicate choices",
          options: ["Same", "Same"],
          requestKey: key(),
        }),
        422,
      );
      ok(
        await owner.request("/calendar/add", {
          title: "Bad date",
          detail: "",
          startsAt: "2026-02-30T00:00:00.000Z",
          allDay: true,
          annually: false,
          timeZone: "Europe/London",
          requestKey: key(),
        }),
        422,
      );
    });
    it("only a memory creator or administrator can remove it", async () => {
      const { owner, member, admin } = get();
      const draft = {
        title: "Regression removal permission",
        story: "Story",
        requestKey: key(),
      };
      const memory = ok(
        await owner.request("/memories/add", memoryForm(draft)),
        200,
      ).memories.find((x) => x.title === draft.title);
      ok(
        await member.request(
          "/memories/remove",
          memoryForm({ id: memory.id, version: memory.version }),
        ),
        403,
      );
      ok(
        await admin.request(
          "/memories/remove",
          memoryForm({ id: memory.id, version: memory.version }),
        ),
        200,
      );
      assert.equal(
        ok(await owner.request("/memories"), 200).memories.some(
          (x) => x.id === memory.id,
        ),
        false,
      );
    });
    it("only the poll creator or admin can manage its question or close it", async () => {
      const { owner, member, admin } = get();
      const poll = ok(
        await owner.request("/polls/add", {
          title: "Regression poll permission",
          detail: "Details",
          options: ["A", "B"],
          requestKey: key(),
        }),
        200,
      ).polls.find((x) => x.title === "Regression poll permission");
      ok(
        await member.request("/polls/edit", {
          id: poll.id,
          version: poll.version,
          title: "Attempted change",
          detail: "",
        }),
        403,
      );
      ok(
        await member.request("/polls/close", {
          id: poll.id,
          version: poll.version,
          closed: true,
        }),
        403,
      );
      ok(
        await member.request("/polls/remove", {
          id: poll.id,
          version: poll.version,
        }),
        403,
      );
      ok(
        await admin.request("/polls/close", {
          id: poll.id,
          version: poll.version,
          closed: true,
        }),
        200,
      );
    });
  });
}
