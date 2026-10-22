import { describe, it } from "node:test";
import assert from "node:assert/strict";
import {
  expectStatus as ok,
  key,
  memoryForm,
  png,
} from "../support/environment.mjs";
export function savingTests(get) {
  describe("saved family content", () => {
    it("shopping saves, retries idempotently and records the real creator/editor", async () => {
      const { owner, member, users } = get();
      const draft = {
        title: "Regression shopping",
        important: true,
        requestKey: key(),
        createdBy: 999,
        updatedBy: 999,
      };
      const first = ok(
        await owner.request("/shopping/add", draft),
        200,
      ).items.find((x) => x.title === draft.title);
      assert.ok(first);
      assert.equal(first.createdBy.id, users.owner.id);
      assert.equal(first.important, true);
      const retry = ok(
        await owner.request("/shopping/add", draft),
        200,
      ).items.filter((x) => x.title === draft.title);
      assert.equal(retry.length, 1);
      ok(
        await member.request("/shopping/edit", {
          id: first.id,
          version: first.version,
          title: "Regression shared edit",
          important: false,
        }),
        200,
      );
      const saved = ok(await owner.request("/shopping"), 200).items.find(
        (x) => x.id === first.id,
      );
      assert.equal(saved.title, "Regression shared edit");
      assert.equal(saved.createdBy.id, users.owner.id);
      assert.equal(saved.updatedBy.id, users.member.id);
      const checked = ok(
        await member.request("/shopping/check", {
          id: saved.id,
          version: saved.version,
          checked: true,
        }),
        200,
      ).items.find((x) => x.id === saved.id);
      assert.equal(checked.checked, true);
      const fresh = await get().client().login("owner");
      assert.equal(
        ok(await fresh.request("/shopping"), 200).items.find(
          (x) => x.id === saved.id,
        ).checked,
        true,
      );
    });
    it("calendar retains its annual civil date and attributed shared edits", async () => {
      const { owner, member, users } = get();
      const draft = {
        title: "Regression annual date",
        detail: "Original",
        startsAt: "2026-11-18T00:00:00.000Z",
        timeZone: "Europe/London",
        allDay: true,
        annually: true,
        requestKey: key(),
      };
      const event = ok(
        await owner.request("/calendar/add", draft),
        200,
      ).events.find((x) => x.title === draft.title);
      ok(
        await member.request("/calendar/edit", {
          ...draft,
          id: event.id,
          version: event.version,
          detail: "Shared edit",
        }),
        200,
      );
      const saved = ok(await owner.request("/calendar"), 200).events.find(
        (x) => x.id === event.id,
      );
      assert.equal(saved.annually, true);
      assert.equal(saved.startsAt, draft.startsAt);
      assert.equal(saved.detail, "Shared edit");
      assert.equal(saved.createdBy.id, users.owner.id);
      assert.equal(saved.updatedBy.id, users.member.id);
    });
    it("private notes retain every field after editing and a fresh session", async () => {
      const { owner } = get();
      const draft = {
        title: "Regression private fields",
        header: "Header",
        body: "Body\nSecond line",
        footer: "Footer",
        noteDate: "2026-10-22",
        requestKey: key(),
      };
      const first = ok(
        await owner.request("/notes/add", draft),
        200,
      ).notes.find((x) => x.title === draft.title);
      const replay = ok(
        await owner.request("/notes/add", draft),
        200,
      ).notes.filter((x) => x.title === draft.title);
      assert.equal(replay.length, 1);
      ok(
        await owner.request("/notes/edit", {
          ...draft,
          id: first.id,
          version: first.version,
          footer: "Updated footer",
        }),
        200,
      );
      const fresh = await get().client().login("owner");
      const saved = ok(await fresh.request("/notes"), 200).notes.find(
        (x) => x.id === first.id,
      );
      for (const field of ["title", "header", "body", "noteDate"])
        assert.equal(saved[field], draft[field]);
      assert.equal(saved.footer, "Updated footer");
      assert.ok(saved.createdAt);
      assert.ok(saved.updatedAt);
    });
    it("memory story/photo/reaction persist and original images revalidate privately", async () => {
      const { owner, member, users } = get();
      const draft = {
        title: "Regression image",
        story: "First story",
        photoAlt: "Local raster",
        requestKey: key(),
      };
      const memory = ok(
        await owner.request("/memories/add", memoryForm(draft, png)),
        200,
      ).memories.find((x) => x.title === draft.title);
      assert.equal(memory.photoWidth, 1);
      assert.equal(memory.photoHeight, 1);
      const path = `/memories/photo?id=${memory.id}&v=${memory.photoKey}`;
      const image = await member.request(path);
      assert.equal(image.status, 200);
      assert.deepEqual(image.bytes, png);
      assert.match(image.headers.get("cache-control"), /private/);
      const unchanged = await member.request(path, undefined, {
        "If-None-Match": image.headers.get("etag"),
      });
      assert.equal(unchanged.status, 304);
      assert.equal(unchanged.bytes.length, 0);
      ok(
        await member.request(
          "/memories/edit",
          memoryForm({
            ...draft,
            id: memory.id,
            version: memory.version,
            story: "Second shared story",
          }),
        ),
        200,
      );
      ok(
        await member.request(
          "/memories/react",
          memoryForm({ id: memory.id, liked: true }),
        ),
        200,
      );
      const saved = ok(await owner.request("/memories"), 200).memories.find(
        (x) => x.id === memory.id,
      );
      assert.equal(saved.story, "Second shared story");
      assert.equal(saved.reactions, 1);
      assert.equal(saved.createdBy.id, users.owner.id);
      assert.equal(saved.updatedBy.id, users.member.id);
      const fresh = await get().client().login("member");
      assert.equal(
        ok(await fresh.request("/memories"), 200).memories.find(
          (x) => x.id === memory.id,
        ).liked,
        true,
      );
    });
    it("poll choices and votes persist, closed polls reject changes and admin can audit actors", async () => {
      const { owner, member, admin, users } = get();
      const poll = ok(
        await owner.request("/polls/add", {
          title: "Regression question",
          detail: "Saved detail",
          closesAt: new Date(Date.now() + 4 * 3600000).toISOString(),
          options: ["One", "Two"],
          requestKey: key(),
        }),
        200,
      ).polls.find((x) => x.title === "Regression question");
      ok(
        await member.request("/polls/vote", {
          id: poll.id,
          version: poll.version,
          voteVersion: 0,
          optionId: poll.options[1].id,
        }),
        200,
      );
      const fresh = await get().client().login("member");
      const saved = ok(await fresh.request("/polls"), 200).polls.find(
        (x) => x.id === poll.id,
      );
      assert.equal(saved.ownOptionId, poll.options[1].id);
      assert.equal(saved.totalVotes, 1);
      ok(
        await owner.request("/polls/close", {
          id: poll.id,
          version: poll.version,
          closed: true,
        }),
        200,
      );
      const closed = ok(await owner.request("/polls"), 200).polls.find(
        (x) => x.id === poll.id,
      );
      assert.equal(closed.closed, true);
      ok(
        await member.request("/polls/vote", {
          id: poll.id,
          version: closed.version,
          voteVersion: saved.voteVersion,
          optionId: poll.options[0].id,
        }),
        409,
      );
      const activity = ok(
        await admin.request("/admin/activity?days=0&type=poll"),
        200,
      ).entries;
      assert.ok(
        activity.some(
          (x) =>
            x.entityId === poll.id &&
            x.action === "voted" &&
            x.actor.id === users.member.id,
        ),
      );
      assert.ok(
        activity.some(
          (x) =>
            x.entityId === poll.id &&
            x.action === "created" &&
            x.actor.id === users.owner.id,
        ),
      );
    });
  });
}
