import { before, after, describe, it } from "node:test";
import assert from "node:assert/strict";
import {
  startEnvironment,
  expectStatus as ok,
  key,
} from "./support/environment.mjs";
let env;
before(async () => {
  env = await startEnvironment();
});
after(async () => {
  await env?.stop();
});
const draft = (visibility = "family") => ({
  title: "Regression dinner",
  visibility,
  meals: [{ title: "Private pasta marker" }, { title: "Private curry marker" }],
  requestKey: key(),
});
const find = (snapshot, id) =>
  snapshot.widgets.find((widget) => widget.id === id);
async function install(visibility) {
  const body = draft(visibility);
  const snapshot = ok(await env.owner.request("/widgets/add", body), 200);
  return snapshot.widgets.find(
    (widget) => widget.title === body.title && widget.visibility === visibility,
  );
}
async function spin(actor, widget, requestKey = key()) {
  return ok(
    await actor.request("/widgets/spin", {
      id: widget.id,
      version: widget.version,
      requestKey,
    }),
    200,
  );
}
const mealToday = () =>
  new Intl.DateTimeFormat("en-CA", {
    timeZone: "Europe/London",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
const publicationBody = (widget, trial, requestKey = key()) => ({
  id: widget.id,
  version: widget.version,
  spinId: trial.id,
  forDate: mealToday(),
  previousPublicationId: widget.publications[0]?.id ?? null,
  requestKey,
});

describe("Dinner Spinner privacy, saving and family decisions", () => {
  it("keeps personal installations inaccessible to members and admins, including writes", async () => {
    const widget = await install("private");
    await spin(env.owner, widget);
    for (const actor of [env.member, env.admin]) {
      assert.equal(
        find(ok(await actor.request("/widgets"), 200), widget.id),
        undefined,
      );
      ok(
        await actor.request("/widgets/spin", {
          id: widget.id,
          version: widget.version,
          requestKey: key(),
        }),
        404,
      );
      ok(
        await actor.request("/widgets/remove", {
          id: widget.id,
          version: widget.version,
        }),
        404,
      );
    }
    const fresh = await env.client().login("owner");
    assert.equal(
      find(ok(await fresh.request("/widgets"), 200), widget.id).spins.length,
      1,
    );
    ok(
      await env.owner.request(
        "/widgets/publish",
        publicationBody(
          widget,
          find(ok(await env.owner.request("/widgets"), 200), widget.id)
            .spins[0],
        ),
      ),
      422,
    );
  });
  it("allows shared private trials without leaking another actor’s history or replacing the publication", async () => {
    const widget = await install("family");
    const requestKey = key();
    const first = await spin(env.owner, widget, requestKey);
    const retry = await spin(env.owner, widget, requestKey);
    assert.equal(first.selectedSpin.id, retry.selectedSpin.id);
    assert.equal(find(retry, widget.id).spins.length, 1);
    const memberSpin = await spin(env.member, widget);
    assert.deepEqual(
      find(memberSpin, widget.id).spins.map((s) => s.actor.id),
      [env.member.user.id],
    );
    const own = find(ok(await env.owner.request("/widgets"), 200), widget.id);
    assert.equal(own.spins.length, 1);
    assert.equal(own.version, widget.version);
    assert.deepEqual(own.publications, []);
    ok(
      await env.member.request(
        "/widgets/publish",
        publicationBody(widget, first.selectedSpin),
      ),
      422,
    );
    const body = publicationBody(widget, first.selectedSpin);
    const saved = ok(await env.owner.request("/widgets/publish", body), 200);
    const publication = find(saved, widget.id).publications[0];
    assert.equal(publication.meal, first.selectedSpin.meal);
    assert.ok(publication.members.every((member) => member.choice === null));
    assert.equal(
      find(
        ok(await env.owner.request("/widgets/publish", body), 200),
        widget.id,
      ).publications.length,
      1,
    );
    const afterTrial = await spin(env.member, find(saved, widget.id));
    assert.equal(
      find(afterTrial, widget.id).publications[0].id,
      publication.id,
    );
    const adminSnapshot = find(
      ok(await env.admin.request("/widgets"), 200),
      widget.id,
    );
    assert.deepEqual(adminSnapshot.spins, []);
    assert.equal(adminSnapshot.publications[0].id, publication.id);
    // Other members can use the wheel, but cannot edit its owner-controlled meals.
    ok(
      await env.member.request("/widgets/edit", {
        ...draft(),
        id: widget.id,
        version: find(saved, widget.id).version,
      }),
      403,
    );
  });
  it("persists per-user responses, rejects stale edits and keeps replies with replaced suggestions", async () => {
    let widget = await install("family");
    const trial = (await spin(env.owner, widget)).selectedSpin;
    widget = find(
      ok(
        await env.owner.request(
          "/widgets/publish",
          publicationBody(widget, trial),
        ),
        200,
      ),
      widget.id,
    );
    const publication = widget.publications[0];
    const reply = {
      id: widget.id,
      publicationId: publication.id,
      choice: "agree",
      responseVersion: 0,
    };
    const response = ok(
      await env.member.request("/widgets/respond", reply),
      200,
    );
    assert.equal(
      find(response, widget.id).publications[0].members.find(
        (m) => m.id === env.member.user.id,
      ).choice,
      "agree",
    );
    const fresh = await env.client().login("member");
    const member = find(
      ok(await fresh.request("/widgets"), 200),
      widget.id,
    ).publications[0].members.find((m) => m.id === env.member.user.id);
    assert.equal(member.choice, "agree");
    ok(
      await env.member.request("/widgets/respond", {
        ...reply,
        choice: "another",
      }),
      409,
    );
    ok(
      await fresh.request("/widgets/respond", {
        ...reply,
        choice: "another",
        responseVersion: member.version,
      }),
      200,
    );
    const next = (await spin(env.owner, widget)).selectedSpin;
    const replaced = find(
      ok(
        await env.owner.request(
          "/widgets/publish",
          publicationBody(widget, next),
        ),
        200,
      ),
      widget.id,
    );
    assert.ok(replaced.publications[0].members.every((m) => m.choice === null));
    assert.equal(
      replaced.publications[1].members.find((m) => m.id === env.member.user.id)
        .choice,
      "another",
    );
    ok(
      await env.member.request("/widgets/respond", {
        ...reply,
        choice: null,
        responseVersion: member.version + 1,
      }),
      409,
    );
    const latest = replaced.publications[0];
    const agreed = ok(
      await fresh.request("/widgets/respond", {
        ...reply,
        publicationId: latest.id,
      }),
      200,
    );
    const version = find(agreed, widget.id).publications[0].members.find(
      (m) => m.id === env.member.user.id,
    ).version;
    const cleared = ok(
      await fresh.request("/widgets/respond", {
        ...reply,
        publicationId: latest.id,
        choice: null,
        responseVersion: version,
      }),
      200,
    );
    assert.equal(
      find(cleared, widget.id).publications[0].members.find(
        (m) => m.id === env.member.user.id,
      ).choice,
      null,
    );
  });
  it("enforces authentication and CSRF", async () => {
    ok(await env.client().request("/widgets"), 401);
    ok(
      await env.owner.request("/widgets/add", draft(), { "X-CSRF-Token": "" }),
      403,
    );
  });
});
const mealDay = (ago = 0) => {
  const date = new Date(mealToday() + "T12:00:00Z");
  date.setUTCDate(date.getUTCDate() - ago);
  return date.toISOString().slice(0, 10);
};
async function publishMeal(
  actor,
  widget,
  date = mealToday(),
  mealType = "dinner",
) {
  const trial = (await spin(actor, widget)).selectedSpin;
  const previous = widget.publications.find(
    (item) =>
      item.current && item.forDate === date && item.mealType === mealType,
  );
  return find(
    ok(
      await actor.request("/widgets/publish", {
        id: widget.id,
        version: widget.version,
        spinId: trial.id,
        forDate: date,
        mealType,
        previousPublicationId: previous?.id ?? null,
        requestKey: key(),
      }),
      200,
    ),
    widget.id,
  );
}
const slot = (widget, date, type) =>
  widget.publications.find(
    (item) => item.current && item.forDate === date && item.mealType === type,
  );

describe("Meal slots and seven-day diary", () => {
  it("keeps lunch, dinner and dates independent, including responses and replacement history", async () => {
    let widget = await install("family");
    widget = await publishMeal(env.owner, widget, mealToday(), "lunch");
    const lunch = slot(widget, mealToday(), "lunch");
    assert.ok(
      lunch.members.every((member) => member.choice === null),
      "Default choice must never fabricate votes",
    );
    widget = await publishMeal(env.owner, widget, mealToday(), "dinner");
    const dinner = slot(widget, mealToday(), "dinner");
    assert.equal(slot(widget, mealToday(), "lunch").id, lunch.id);
    widget = find(
      ok(
        await env.member.request("/widgets/respond", {
          id: widget.id,
          publicationId: lunch.id,
          choice: "agree",
          responseVersion: 0,
        }),
        200,
      ),
      widget.id,
    );
    assert.equal(
      slot(widget, mealToday(), "lunch").members.find(
        (m) => m.id === env.member.user.id,
      ).choice,
      "agree",
    );
    assert.ok(dinner.members.every((member) => member.choice === null));
    widget = await publishMeal(env.owner, widget, mealDay(1), "lunch");
    widget = await publishMeal(env.owner, widget, mealToday(), "lunch");
    assert.equal(slot(widget, mealToday(), "dinner").id, dinner.id);
    assert.notEqual(slot(widget, mealToday(), "lunch").id, lunch.id);
    assert.equal(
      widget.publications.find((p) => p.id === lunch.id).current,
      false,
    );
    assert.equal(
      widget.publications
        .find((p) => p.id === lunch.id)
        .members.find((m) => m.id === env.member.user.id).choice,
      "agree",
    );
    const fresh = await env.client().login("owner");
    const reread = find(ok(await fresh.request("/widgets"), 200), widget.id);
    assert.equal(reread.publications.filter((p) => p.current).length, 3);
    ok(
      await env.owner.request("/widgets/respond", {
        id: widget.id,
        publicationId: lunch.id,
        choice: "agree",
        responseVersion: 0,
      }),
      409,
    );
  });
  it("persists eaten actor/time, supports corrections and protects completed/future/stale records", async () => {
    let widget = await install("family");
    widget = await publishMeal(env.owner, widget, mealToday(), "lunch");
    widget = await publishMeal(env.owner, widget, mealToday(), "dinner");
    const lunch = slot(widget, mealToday(), "lunch"),
      dinner = slot(widget, mealToday(), "dinner");
    const eaten = {
      id: widget.id,
      version: widget.version,
      publicationId: lunch.id,
      eaten: true,
    };
    widget = find(
      ok(await env.owner.request("/widgets/eaten", eaten), 200),
      widget.id,
    );
    const confirmed = slot(widget, mealToday(), "lunch");
    assert.ok(confirmed.eatenAt);
    assert.equal(confirmed.eatenBy.id, env.owner.user.id);
    assert.equal(slot(widget, mealToday(), "dinner").eatenAt, null);
    assert.ok(
      confirmed.members.every((member) => member.choice === null),
      "Eaten is not a vote",
    );
    const retry = find(
      ok(await env.owner.request("/widgets/eaten", eaten), 200),
      widget.id,
    );
    assert.equal(slot(retry, mealToday(), "lunch").eatenAt, confirmed.eatenAt);
    const fresh = await env.client().login("owner");
    assert.deepEqual(
      slot(
        find(ok(await fresh.request("/widgets"), 200), widget.id),
        mealToday(),
        "lunch",
      ).eatenBy,
      confirmed.eatenBy,
    );
    ok(
      await env.owner.request("/widgets/respond", {
        id: widget.id,
        publicationId: lunch.id,
        choice: "agree",
        responseVersion: 0,
      }),
      409,
    );
    const trial = (await spin(env.owner, widget)).selectedSpin;
    ok(
      await env.owner.request("/widgets/publish", {
        ...publicationBody(widget, trial),
        forDate: mealToday(),
        mealType: "lunch",
        previousPublicationId: lunch.id,
      }),
      409,
    );
    ok(
      await env.owner.request("/widgets/eaten", { ...eaten, eaten: false }),
      409,
    );
    ok(
      await env.member.request("/widgets/respond", {
        id: widget.id,
        publicationId: dinner.id,
        choice: "agree",
        responseVersion: 0,
      }),
      200,
    );
    widget = find(
      ok(
        await env.owner.request("/widgets/eaten", {
          ...eaten,
          version: widget.version,
          eaten: false,
        }),
        200,
      ),
      widget.id,
    );
    assert.equal(slot(widget, mealToday(), "lunch").eatenAt, null);
    assert.equal(slot(widget, mealToday(), "lunch").eatenBy, null);
    widget = await publishMeal(env.owner, widget, mealDay(-1), "lunch");
    ok(
      await env.owner.request("/widgets/eaten", {
        id: widget.id,
        version: widget.version,
        publicationId: slot(widget, mealDay(-1), "lunch").id,
        eaten: true,
      }),
      422,
    );
    ok(
      await env.owner.request(
        "/widgets/eaten",
        {
          id: widget.id,
          version: widget.version,
          publicationId: lunch.id,
          eaten: true,
        },
        { "X-CSRF-Token": "" },
      ),
      403,
    );
  });
  it("returns all fourteen current lunch/dinner slots across seven days despite the old ten-row cap", async () => {
    let widget = await install("family");
    for (let day = 0; day < 7; day++)
      for (const type of ["lunch", "dinner"])
        widget = await publishMeal(env.owner, widget, mealDay(day), type);
    const current = widget.publications.filter(
      (p) => p.current && p.forDate >= mealDay(6) && p.forDate <= mealToday(),
    );
    assert.equal(current.length, 14);
    for (let day = 0; day < 7; day++)
      for (const type of ["lunch", "dinner"])
        assert.ok(slot(widget, mealDay(day), type));
    const first = slot(widget, mealDay(6), "lunch");
    widget = find(
      ok(
        await env.owner.request("/widgets/eaten", {
          id: widget.id,
          version: widget.version,
          publicationId: first.id,
          eaten: true,
        }),
        200,
      ),
      widget.id,
    );
    const fresh = await env.client().login("owner");
    assert.ok(
      slot(
        find(ok(await fresh.request("/widgets"), 200), widget.id),
        mealDay(6),
        "lunch",
      ).eatenAt,
    );
  });
});
async function editMeals(widget, meals, extra = {}) {
  return find(
    ok(
      await env.owner.request("/widgets/edit", {
        id: widget.id,
        version: widget.version,
        title: widget.title,
        visibility: widget.visibility,
        meals: meals.map(({ id, title }) => ({ id, title })),
        ...extra,
      }),
      200,
    ),
    widget.id,
  );
}
describe("Adversarial widget inputs and recovery boundaries", () => {
  it("rejects blank, invisible, oversized, malformed and control-character input without creating data", async () => {
    const before = ok(await env.owner.request("/widgets"), 200).widgets.length;
    for (const title of [
      "",
      "   ",
      "\u200b\u2060",
      "\u200d\ufe0f",
      "\u0301",
      "\u00a0",
      "x".repeat(121),
      "meal\nname",
      "meal\u202ename",
      null,
      false,
      42,
      [],
      {},
    ]) {
      ok(await env.owner.request("/widgets/add", { ...draft(), title }), 422);
    }
    for (const meals of [
      null,
      {},
      [],
      [{ title: "One" }],
      Array.from({ length: 13 }, (_, i) => ({ title: "Meal " + i })),
      [{ title: "same" }, { title: " SAME " }],
      [{ title: "Curry" }, { title: "Cur\u200bry" }],
      [{ title: "Äpfel" }, { title: "äpfel" }],
      [null, { title: "valid" }],
      [{ title: [] }, { title: "valid" }],
      [{ title: "x".repeat(81) }, { title: "valid" }],
    ]) {
      ok(await env.owner.request("/widgets/add", { ...draft(), meals }), 422);
    }
    ok(
      await env.owner.request("/widgets/add", {
        ...draft(),
        title: "x".repeat(9000),
      }),
      413,
    );
    ok(
      await env.owner.request("/widgets/add", draft(), {
        "Content-Type": "text/plain",
      }),
      415,
    );
    assert.equal(
      ok(await env.owner.request("/widgets"), 200).widgets.length,
      before,
    );
  });
  it("accepts valid Unicode, punctuation and literal markup as plain data, normalizing spaces", async () => {
    const body = {
      ...draft(),
      title: "  Family\u00a0  meals  ",
      meals: [
        { title: "🍜  Noodles & veg" },
        { title: "<img src=x onerror=alert(1)>" },
      ],
    };
    const saved = ok(
      await env.owner.request("/widgets/add", body),
      200,
    ).widgets.find((w) => w.title === "Family meals");
    assert.equal(saved.meals[0].title, "🍜 Noodles & veg");
    assert.equal(saved.meals[1].title, body.meals[1].title);
    assert.equal(saved.owner.id, env.owner.user.id);
  });
  it("rejects forged identifiers, versions, meal types, dates and response values", async () => {
    let widget = await install("family");
    for (const id of [
      "",
      "0",
      "-1",
      "01",
      "1 OR 1=1",
      "9".repeat(40),
      1,
      {},
      [],
    ])
      ok(
        await env.owner.request("/widgets/spin", {
          id,
          version: widget.version,
          requestKey: key(),
        }),
        422,
      );
    for (const version of [null, -1, 0, "1", {}, []])
      ok(
        await env.owner.request("/widgets/spin", {
          id: widget.id,
          version,
          requestKey: key(),
        }),
        422,
      );
    const trial = (await spin(env.owner, widget)).selectedSpin;
    const body = { ...publicationBody(widget, trial), forDate: mealToday() };
    for (const forDate of [
      "",
      null,
      {},
      "2026-02-30",
      "1000-01-01",
      "9999-12-31",
      mealDay(7),
      mealDay(-366),
    ])
      ok(
        await env.owner.request("/widgets/publish", {
          ...body,
          forDate,
          requestKey: key(),
        }),
        422,
      );
    for (const mealType of ["", null, {}, "plane", "LUNCH", 1])
      ok(
        await env.owner.request("/widgets/publish", {
          ...body,
          mealType,
          requestKey: key(),
        }),
        422,
      );
    widget = find(
      ok(await env.owner.request("/widgets/publish", body), 200),
      widget.id,
    );
    for (const choice of ["", true, 0, {}, [], "yes"])
      ok(
        await env.owner.request("/widgets/respond", {
          id: widget.id,
          publicationId: widget.publications[0].id,
          choice,
          responseVersion: 0,
        }),
        422,
      );
    for (const eaten of ["", 1, null, {}, []])
      ok(
        await env.owner.request("/widgets/eaten", {
          id: widget.id,
          version: widget.version,
          publicationId: widget.publications[0].id,
          eaten,
        }),
        422,
      );
  });
  it("does not revive a trial after its meal was renamed away and back", async () => {
    let widget = await install("family");
    const trial = (await spin(env.owner, widget)).selectedSpin;
    widget = await editMeals(
      widget,
      widget.meals.map((meal) => ({
        ...meal,
        title: meal.id === trial.mealId ? "Temporary rename" : meal.title,
      })),
    );
    widget = await editMeals(
      widget,
      widget.meals.map((meal) => ({
        ...meal,
        title: meal.id === trial.mealId ? trial.meal : meal.title,
      })),
    );
    assert.ok(
      widget.meals.find((meal) => meal.id === trial.mealId).version >
        trial.mealVersion,
    );
    ok(
      await env.owner.request("/widgets/publish", {
        ...publicationBody(widget, trial),
        forDate: mealToday(),
      }),
      422,
    );
    assert.equal(widget.publications.length, 0);
  });
  it("preserves minimum meals, rejects bulk omissions and foreign meal IDs", async () => {
    let widget = await install("family");
    const foreign = await install("private");
    ok(
      await env.owner.request("/widgets/remove-meal", {
        id: widget.id,
        version: widget.version,
        mealId: widget.meals[0].id,
      }),
      422,
    );
    ok(
      await env.owner.request("/widgets/edit", {
        ...draft(),
        id: widget.id,
        version: widget.version,
        meals: [widget.meals[0], { title: "New" }],
      }),
      422,
    );
    ok(
      await env.owner.request("/widgets/edit", {
        ...draft(),
        id: widget.id,
        version: widget.version,
        meals: [foreign.meals[0], widget.meals[0]],
      }),
      422,
    );
    widget = await editMeals(widget, [...widget.meals, { title: "New soup" }]);
    const trial = (await spin(env.owner, widget)).selectedSpin;
    const removed = find(
      ok(
        await env.owner.request("/widgets/remove-meal", {
          id: widget.id,
          version: widget.version,
          mealId: trial.mealId,
        }),
        200,
      ),
      widget.id,
    );
    ok(
      await env.owner.request("/widgets/publish", {
        ...publicationBody(removed, trial),
        forDate: mealToday(),
      }),
      422,
    );
    assert.equal(removed.meals.length, 2);
  });
  it("rejects stale competing saves, removed widgets and changes to personal widgets", async () => {
    let widget = await install("family");
    const trial = (await spin(env.owner, widget)).selectedSpin;
    const body = { ...publicationBody(widget, trial), forDate: mealToday() };
    const writes = await Promise.all([
      env.owner.request("/widgets/publish", body),
      env.owner.request("/widgets/edit", {
        id: widget.id,
        version: widget.version,
        title: "Competing edit",
        visibility: "family",
        meals: widget.meals,
      }),
    ]);
    assert.deepEqual(writes.map((result) => result.status).sort(), [200, 409]);
    widget = find(ok(await env.owner.request("/widgets"), 200), widget.id);
    widget = await editMeals(widget, widget.meals, { visibility: "private" });
    ok(
      await env.member.request("/widgets/spin", {
        id: widget.id,
        version: widget.version,
        requestKey: key(),
      }),
      404,
    );
    ok(
      await env.owner.request("/widgets/publish", {
        ...body,
        version: widget.version,
        requestKey: key(),
      }),
      422,
    );
    ok(
      await env.owner.request("/widgets/remove", {
        id: widget.id,
        version: widget.version,
      }),
      200,
    );
    ok(
      await env.owner.request("/widgets/spin", {
        id: widget.id,
        version: widget.version,
        requestKey: key(),
      }),
      404,
    );
  });
});
describe("Widget capacity, retries and per-user hiding", () => {
  it("hiding is personal and never hides the owner’s shared installation", async () => {
    const widget = await install("family");
    const hidden = find(
      ok(
        await env.member.request("/widgets/hide", {
          id: widget.id,
          hidden: true,
        }),
        200,
      ),
      widget.id,
    );
    assert.equal(hidden.hidden, true);
    assert.equal(
      find(ok(await env.owner.request("/widgets"), 200), widget.id).hidden,
      false,
    );
    assert.equal(
      find(ok(await env.admin.request("/widgets"), 200), widget.id).hidden,
      false,
    );
    assert.equal(
      find(
        ok(
          await env.member.request("/widgets/hide", {
            id: widget.id,
            hidden: false,
          }),
          200,
        ),
        widget.id,
      ).hidden,
      false,
    );
  });
  it("caps active installations at thirty while allowing safe retries and removal", async () => {
    const body = { ...draft("private"), title: "Capacity retry" };
    const original = ok(
      await env.owner.request("/widgets/add", body),
      200,
    ).widgets.find((w) => w.title === body.title);
    let snapshot = ok(await env.owner.request("/widgets"), 200);
    while (
      snapshot.widgets.filter((w) => w.owner.id === env.owner.user.id).length <
      30
    )
      snapshot = ok(
        await env.owner.request("/widgets/add", draft("private")),
        200,
      );
    ok(await env.owner.request("/widgets/add", draft("private")), 422);
    const retry = ok(await env.owner.request("/widgets/add", body), 200);
    assert.equal(
      retry.widgets.filter((w) => w.owner.id === env.owner.user.id).length,
      30,
    );
    assert.ok(find(retry, original.id));
    ok(
      await env.owner.request("/widgets/remove", {
        id: original.id,
        version: original.version,
      }),
      200,
    );
    ok(await env.owner.request("/widgets/add", draft("private")), 200);
  });
});
describe('Unicode-equivalent meal choices',()=>{
  it('rejects composed/decomposed accent duplicates so they cannot weight the wheel twice',async()=>{
    ok(await env.owner.request('/widgets/add',{...draft(),meals:[{title:'Café'},{title:'Cafe\u0301'}]}),422)
  })
})
