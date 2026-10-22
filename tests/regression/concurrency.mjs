import { describe, it } from "node:test";
import assert from "node:assert/strict";
import {
  expectStatus as ok,
  key,
  memoryForm,
} from "../support/environment.mjs";
export function concurrencyTests(get) {
  describe("concurrent edits and version protection", () => {
    for (const domain of ["shopping", "calendar", "memories", "notes", "polls"])
      it(`${domain}: one of two writes wins and stale edit/remove cannot overwrite it`, async () => {
        const { owner, member } = get();
        const route = "/" + domain;
        const title = "Regression concurrency " + domain;
        const draft =
          domain === "shopping"
            ? { title, important: false }
            : domain === "calendar"
              ? {
                  title,
                  detail: "Original",
                  startsAt: "2027-01-02T00:00:00.000Z",
                  timeZone: "Europe/London",
                  allDay: true,
                  annually: false,
                }
              : domain === "memories"
                ? { title, story: "Original", photoAlt: "" }
                : domain === "notes"
                  ? {
                      title,
                      header: "",
                      body: "Original",
                      footer: "",
                      noteDate: "2026-10-22",
                    }
                  : {
                      title,
                      detail: "Original",
                      closesAt: new Date(
                        Date.now() + 4 * 3600000,
                      ).toISOString(),
                      options: ["A", "B"],
                    };
        const pack = (body) =>
          domain === "memories" ? memoryForm(body) : body;
        const field = {
          shopping: "items",
          calendar: "events",
          memories: "memories",
          notes: "notes",
          polls: "polls",
        }[domain];
        const first = ok(
          await owner.request(
            route + "/add",
            pack({ ...draft, requestKey: key() }),
          ),
          200,
        )[field].find((x) => x.title === title);
        const second = ["notes", "polls"].includes(domain)
          ? await get().client().login("owner")
          : member;
        const changes = ["Winner A", "Winner B"].map((suffix) => ({
          ...draft,
          title: title + " " + suffix,
          id: first.id,
          version: first.version,
        }));
        const results = await Promise.all([
          owner.request(route + "/edit", pack(changes[0])),
          second.request(route + "/edit", pack(changes[1])),
        ]);
        assert.deepEqual(results.map((r) => r.status).sort(), [200, 409]);
        const beforeNoOp = await owner.request(route);
        const saved = ok(beforeNoOp, 200)[field].find((x) => x.id === first.id);
        assert.equal(saved.version, first.version + 1);
        assert.ok(changes.some((x) => x.title === saved.title));
        ok(
          await owner.request(
            route + "/remove",
            pack({ id: first.id, version: first.version }),
          ),
          409,
        );
        assert.equal(
          ok(await owner.request(route), 200)[field].find(
            (x) => x.id === first.id,
          ).title,
          saved.title,
        );
        // Repeating a no-op save must not manufacture another version or audit event.
        const noOp = ok(
          await owner.request(
            route + "/edit",
            pack({
              ...draft,
              title: saved.title,
              id: saved.id,
              version: saved.version,
            }),
          ),
          200,
        )[field].find((x) => x.id === first.id);
        assert.equal(noOp.version, saved.version);
        assert.equal(
          (
            await owner.request(route, undefined, {
              "If-None-Match": beforeNoOp.headers.get("etag"),
            })
          ).status,
          304,
        );
        ok(
          await owner.request(
            route + "/remove",
            pack({ id: saved.id, version: saved.version }),
          ),
          200,
        );
        assert.equal(
          ok(await owner.request(route), 200)[field].some(
            (x) => x.id === saved.id,
          ),
          false,
        );
      });
    it("two sessions cannot silently overwrite the same ballot version", async () => {
      const { owner, member } = get();
      const poll = ok(
        await owner.request("/polls/add", {
          title: "Regression competing ballot",
          detail: "",
          closesAt: new Date(Date.now() + 4 * 3600000).toISOString(),
          options: ["A", "B"],
          requestKey: key(),
        }),
        200,
      ).polls.find((x) => x.title === "Regression competing ballot");
      const second = await get().client().login("member");
      const votes = await Promise.all([
        member.request("/polls/vote", {
          id: poll.id,
          version: poll.version,
          voteVersion: 0,
          optionId: poll.options[0].id,
        }),
        second.request("/polls/vote", {
          id: poll.id,
          version: poll.version,
          voteVersion: 0,
          optionId: poll.options[1].id,
        }),
      ]);
      assert.deepEqual(votes.map((x) => x.status).sort(), [200, 409]);
      const saved = ok(await member.request("/polls"), 200).polls.find(
        (x) => x.id === poll.id,
      );
      assert.equal(saved.totalVotes, 1);
      assert.equal(saved.voteVersion, 1);
      const repeated = ok(
        await member.request("/polls/vote", {
          id: poll.id,
          version: poll.version,
          voteVersion: saved.voteVersion,
          optionId: saved.ownOptionId,
        }),
        200,
      ).polls.find((x) => x.id === poll.id);
      assert.equal(repeated.voteVersion, 1);
      assert.equal(repeated.totalVotes, 1);
    });
  });
}
