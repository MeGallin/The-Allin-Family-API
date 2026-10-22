import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { expectStatus as ok, key } from "../support/environment.mjs";
export function privacyTests(get) {
  describe("private ownership and session boundaries", () => {
    it("another member and an admin cannot read/edit/delete an owner note, even with spoofed owner fields", async () => {
      const { owner, member, admin } = get();
      const draft = {
        title: "Regression secret marker",
        header: "Private header",
        body: "Private body marker",
        footer: "Private footer",
        noteDate: "2026-10-22",
        requestKey: key(),
        ownerId: 999,
      };
      const result = ok(await owner.request("/notes/add", draft), 200);
      assert.equal(result.ownerId, get().users.owner.id);
      const note = result.notes.find((x) => x.title === draft.title);
      for (const actor of [member, admin]) {
        const own = ok(
          await actor.request("/notes?ownerId=" + get().users.owner.id),
          200,
        );
        assert.equal(
          own.notes.some((x) => x.id === note.id),
          false,
        );
        assert.equal(JSON.stringify(own).includes(draft.body), false);
        ok(
          await actor.request("/notes/edit", {
            ...draft,
            id: note.id,
            version: note.version,
            body: "Attempted overwrite",
          }),
          404,
        );
        ok(
          await actor.request("/notes/remove", {
            id: note.id,
            version: note.version,
            ownerId: get().users.owner.id,
          }),
          404,
        );
      }
      const saved = ok(await owner.request("/notes"), 200).notes.find(
        (x) => x.id === note.id,
      );
      assert.equal(saved.body, draft.body);
      for (const path of ["/admin/activity?days=0", "/family"])
        assert.equal(
          JSON.stringify(ok(await admin.request(path), 200)).includes(
            draft.body,
          ),
          false,
        );
    });
    it("anonymous clients cannot get collection data, photos or reuse a known ETag", async () => {
      const anonymous = get().client();
      for (const path of [
        "/shopping",
        "/calendar",
        "/memories",
        "/polls",
        "/notes",
        "/admin/activity",
        "/memories/photo?id=1&v=invalid",
      ])
        ok(
          await anonymous.request(path, undefined, {
            "If-None-Match": '"known"',
          }),
          401,
        );
    });
    it("sign-out revokes the session including cached collection validators", async () => {
      const client = await get().client().login("owner");
      const before = await client.request("/notes");
      const cookie = client.cookie;
      ok(await client.request("/auth/logout", {}), 200);
      const replay = get().client();
      replay.cookie = cookie;
      ok(
        await replay.request("/notes", undefined, {
          "If-None-Match": before.headers.get("etag"),
        }),
        401,
      );
    });
    it("private-note validators differ by owner and invalidated notes are reread", async () => {
      const { owner, member } = get();
      const first = await owner.request("/notes");
      const other = await member.request("/notes");
      assert.notEqual(first.headers.get("etag"), other.headers.get("etag"));
      const unchanged = await owner.request("/notes", undefined, {
        "If-None-Match": first.headers.get("etag"),
      });
      assert.equal(unchanged.status, 304);
      assert.equal(unchanged.bytes.length, 0);
      ok(
        await owner.request("/notes/add", {
          title: "Regression validator update",
          body: "New body",
          header: "",
          footer: "",
          noteDate: "2026-10-22",
          requestKey: key(),
        }),
        200,
      );
      const changed = await owner.request("/notes", undefined, {
        "If-None-Match": first.headers.get("etag"),
      });
      assert.equal(changed.status, 200);
      assert.notEqual(changed.headers.get("etag"), first.headers.get("etag"));
    });
  });
}
