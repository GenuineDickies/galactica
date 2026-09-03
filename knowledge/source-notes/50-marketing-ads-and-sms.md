# WKR Marketing Reference — Bin: marketing-1

Distilled from 18 exported conversations (ChatGPT / Claude / Gemini) covering Google Ads, SMS campaigns, SEO, competitive research, fleet outreach, and related marketing strategy for White Knight Roadside (WKR), Portland OR metro. Final/decided versions only; each item attributed to its source conversation.

**Business facts referenced throughout:** solo owner/operator (Jason); no towing, no winching; services = jump starts, lockouts (car unlock only — not locksmith/key replacement), flat tire change/repair/replacement, fuel delivery, battery replacement/install, mobile mechanic work ($125/hr referenced); service area = Portland metro + suburbs (Beaverton base). Phone numbers appearing in materials: (503) 764-3154 (website/SEO) and (503) 974-2741 (SMS/10DLC messages).

---

## 1. Brand & Positioning

### Tagline — FINAL: "We Answer the Call"
*(Tagline for White Knight Roadside — Claude)*
- Replaced "Always Ready" (dropped because it's the Coast Guard motto — "not trying to steal valor").
- Chosen for the double meaning: answering the phone + answering the call of duty (knight theme without overdoing it). Short and sticky.
- Runner-up options generated: "Ready When the Road Isn't", "When the Road Lets You Down, We Show Up", "Your Knight on the Road", "We Answer Every Call".

### Core ad positioning (recurring across all ads conversations)
- Fast, local, licensed/insured, mobile — "we fix it where you are, no towing."
- Speed beats price; explicitly avoid "cheap/free" framing ("Professional, Licensed, Insured" instead).
- "No Towing" stated in ad copy to pre-filter irrelevant calls.

---

## 2. Google Ads — Canonical Campaign Structure

The account strategy evolved across several conversations; the most refined, final structure comes from **Roadside Ad Campaign Strategy (ChatGPT)** (which corrected an earlier Gemini draft from **Effective Google Ads Structure 2026**):

### Campaign: "Roadside Assistance — Non-Towing" (Search, goal = phone calls)
- **Match types at launch:** Phrase + Exact only. No Broad, no Performance Max, no Display, no Search Partners until conversion data is clean.
- **Location targeting:** "Presence: people in or regularly in your targeted locations" — never "Interest".
- **Ad schedule:** ads OFF when unable to answer the phone ("if you don't answer in 3 rings, they call the next guy").

### Seven ad groups (final — organized by buying intent, not by 4 broad services)
1. **Car Lockouts** — "car lockout service", "locked keys in car", "locked out of my car", "car unlock service", "roadside lockout service" (+ exact variants). Group negatives: key replacement, key fob, program key, lost key, ignition repair, house/apartment lockout. Caution on "auto locksmith" / "pop a lock" (locksmith intent). Ad focus: "Locked out of your car? Fast roadside lockout help."
2. **Jump Starts** — "jump start service", "dead battery service", "battery boost near me", "roadside jump start", "mobile jump start". Group negatives: battery replacement/installation/store terms (keeps it separate from Ad Group 7).
3. **Tire Change / Spare Installation** (customer has a spare) — "flat tire change", "roadside tire change", "spare tire installation", "mobile tire change". Group negatives: new/used tire, tire shop, patch, plug, repair, replacement, mount and balance, alignment.
4. **Tire Repair** (plug/patch intent) — "mobile tire repair", "roadside tire repair", "tire plug service", "tire patch service", "nail in tire repair". Group negatives: spare tire, install spare, new/used tire, tire shop, wheels, rims.
5. **Tire Replacement** (no spare / needs a tire brought) — "mobile tire replacement", "flat tire no spare", "emergency tire replacement", "bring me a tire". Group negatives: rotation, alignment, wheels, rims, tire rack, discount tire, les schwab, costco tires.
6. **Mobile Mechanic** (tightest control; broad and expensive) — "mobile mechanic near me", "roadside mechanic", "mechanic comes to you", "car won't start mechanic". Group negatives: engine rebuild, transmission, head gasket, body shop, collision, dealership, jobs, school, salary, diy, youtube.
7. **Battery Replacement** (buyer intent, distinct from jump start) — "mobile battery replacement", "car battery replacement near me", "car battery installation service", "replace car battery at home". Group negatives: jump start, battery boost, charger, tester, free battery test, watch/phone/laptop battery.

### Campaign-level "Non-Towing Negative Keyword Shield" (apply account-wide)
- **Non-towing block:** tow, towing, tow truck, wrecker, flatbed, rollback, winch, winching, stuck in mud, stuck in ditch, ditch recovery, vehicle recovery, impound, repo, repossession, junk car removal, scrap car, sell my car, auction.
- **Information/DIY block:** how to, diy, do it yourself, instructions, tools needed, youtube, video, guide, manual, training, class, course, school, jobs, career, salary, apprentice, certification.
- **Bad-fit/low-intent block:** free, cheap, coupon, amazon, ebay, harbor freight, parts only, tool kit, business for sale, franchise. (Start conservative with "cheap"/"used"/store names; expand from Search Terms report.)
- List-size guidance (*Effective Google Ads Structure 2026 — Gemini*): 10–15 active keywords per ad group; negatives grow to 200+ over time at account level; check Search Terms report weekly.

### Keyword clustering of the raw keyword dump
*(Google Ads Ad Groups — ChatGPT; produced workbook `google_ads_adgroup_organization.xlsx`)*
1,923 cleaned keywords classified into launch groups and negatives:

| Campaign | Ad Group | Keywords |
|---|---|---:|
| Jump Start | Jump Start / Dead Battery Service | 295 |
| Mobile Tire | Flat Tire Change / Spare Install | 221 |
| Mobile Tire | Mobile Tire Repair / Patch / Plug | 196 |
| Mobile Tire | Mobile Tire Replacement / Install | 115 |
| Mobile Tire | Flat Tire Emergency / No Spare / Blowout | 106 |
| Vehicle Lockout | Vehicle Lockout / Car Unlock | 119 |
| Fuel Delivery | Gas / Fuel Delivery | 81 |
| Roadside General | Roadside Assistance - General | 78 |

Excluded buckets: jump-starter products/DIY gear (327 — negatives), battery replacement (128 — run only if actually offered), broad auto repair (33 — review only), how-to/informational (37), free/cheap shoppers (17), towing/flatbed (14), locksmith/key replacement (9), propane (7). Launch order recommendation: Jump Start first, then tire groups, fuel, lockout, roadside general; heavy-truck/commercial and mobile-mechanic only with caution.

### Ad format — important platform change
*(High Call Volume Google Ads; Keyword Planner Bid Ranges Explained; Roadside Ad Campaign Strategy)*
- **Call-Only Ads are deprecated**: new call ads creation removed Feb 2026; existing call ads stop serving Feb 2027. Build on **Responsive Search Ads + Call Assets + Location Assets + Call Reporting + phone-call conversion tracking** instead.
- Pin Headline 1 to a call CTA (e.g., "Emergency Jump Start — 15 Min Arrival" / "Call Now for Immediate Help"); set Calls bid adjustment ~+20%.
- Link Google Business Profile for Location Assets (map-pack "near me" calls; shows distance — people call whoever looks closest).
- Keep sitelinks minimal and service-focused (About Us is a distraction in an emergency).
- Call asset phone number must appear in plain text on the website homepage or the asset gets disapproved.
- Set minimum call length (45–90 sec) on call conversions so misdials don't train Smart Bidding.
- Lockout advertising can trip Google's locksmith verification policy — word it "Car Lockout Service", never "locksmith", and frame the site as automotive roadside.

### RSA copy bank (final flavors)
- Headlines: "Roadside Assistance in Portland" / "Fast Help—Call Now" / "Jump Starts • Lockouts • Tires" / "24/7 Roadside Help Portland" / "Locked Out? We're 30 Mins Away" / "White Knight Roadside Assistance" / "Upfront Pricing" / "Local Mobile Service".
- Descriptions: "Stuck right now? Call for rapid dispatch in Portland. Upfront pricing, mobile service, pay by card." / "Jump starts, lockouts, tire changes, fuel delivery. Real-time ETA. Call now." / "No towing — we fix it where you are. Licensed, insured, local, 24/7."
- Callouts: 30-Min Response, Licensed & Insured, 24/7 Availability, Flat-Rate Pricing, Card Accepted. Structured snippet: Services: Jump Start, Lockout, Tire Change, Fuel Delivery.
- Competitor intercept angle (*Google Ads campaign design — ChatGPT*): bid on [AAA roadside assistance Portland], "Urgently roadside help", Honk, Allstate — "Skip the Wait — Faster than AAA, no memberships, no call centers."

### Budgets & bidding
- Early plan (*Google Ads campaign design — ChatGPT*): start ~$25–30/day (~$750–900/mo). Final 5-campaign split: Emergency Roadside (call-focused) $15 / Battery Installs $5 / Mobile Mechanic $5 / Competitor Intercept $2 / Display Retargeting $1 (30-day non-converter audience, "Still Need Roadside Help?" creative). Geo: 30-mile radius from Beaverton (Portland, Hillsboro, Tigard, Lake Oswego, Gresham, West Linn, Tualatin); Vancouver WA excluded initially.
- Later reality (*Keyword Planner Bid Ranges Explained — Gemini*): budget was $150/day with **zero impressions** — diagnosis: Max CPC bids far below top-of-page ranges (e.g., $0.86–$2.11 vs. a $5–$15+ market; $9.31 on "car unlock service" vs ~$15–20 high range). Lesson: check "Top of page bid (low/high range)" columns; if Max CPC < low range, the ad simply doesn't show, and low bids can also hide the call button.
- Manual CPC launch plan (*Google Ads API Roadside Assistance Strategy — Gemini*): Manual CPC + Enhanced CPC at launch (avoid Smart Bidding learning-phase waste); tiered bids — Tier 1 "Emergency Now" (lockout, jump, tire change) ≈110% of high top-of-page benchmark ($9–$13.50 starts); Tier 2 scheduled/commercial (mobile mechanic, battery) ≈80% ($10–$11.50); Tier 3 generic "roadside assistance" capped $5–7. Modifiers: Mobile +25–30%, Desktop −50%, Portland core +15%, after-hours +20%. Move to Maximize Conversions/tCPA after ~20 tracked conversions. Rules of thumb: CTR < 3% → fix copy, not bid; Lost IS (rank) > 30–50% → raise bid; CPC > $18 → pause keyword. **Caveat:** the specific bid dollar figures in that conversation were Gemini's estimates, not live API pulls (it admitted this when challenged) — verify in Keyword Planner (Portland OR + 30 mi) before relying on them.
- Dayparting strategy: bid up +25% morning rush (6–9 AM, dead batteries at home), +20% evening rush (4–8 PM), +35% late night if actually answering; −10 to −20% midday research hours. "Weather Warrior": manually raise jump-start/battery bids ~50% for 48 hours ahead of cold snaps.

### Why clicks don't become calls (diagnostic checklist)
*(Keyword Planner Bid Ranges Explained — Gemini)*
Slow site/extra click friction; no local trust signals in ad ("Local Portland… 15 Min Arrival"); sticker shock (put "Starting at $X" in assets); accidental/bot clicks (check Invalid Clicks); Call Reporting turned off (calls happen but show as 0 conversions). Fixes: sticky click-to-call header on mobile, headline pinning, call asset active, Call Details report to see 0-second hang-ups.

### Weekly optimization loop
*(High Call Volume Google Ads — ChatGPT)*
1) Search Terms report → add negatives; 2) shift budget to converting services; 3) bid up peak hours; 4) improve landing pages for top 2 ad groups only; 5) review call outcomes, mark qualified vs unqualified.

### Google Ads API / automation architecture
*(Google Ads API Overview — ChatGPT; Google Ads Campaign Prompt — ChatGPT)*
- Two paths: official **Google Ads API** (has official PHP client library; access levels: Explorer → Basic 15k ops/day → Standard) for real campaign creation; official **Google Ads MCP server** is read-only (analysis only); the Google Marketing Solutions GitHub MCP has mutations but is experimental (disabled by default via `ADS_MCP_ENABLE_MUTATIONS`).
- Adopted design for the WKR app: **AI Campaign Drafting System** — AI reads business rules (no towing, Portland area, service list, pricing, hours) → generates draft campaign JSON → business-rule validator (no towing keywords except negatives, no false claims, budget cap, geo cap) → API creates everything **paused** → human review → enable. Reporting side: MCP/API → AI performance analyst suggesting pauses/negatives/budget shifts.
- Keyword research workflow (baked into the reusable handoff prompt): resolve geo target constants (Portland + realistic nearby cities) → `KeywordPlanIdeaService.GenerateKeywordIdeas` (keyword + URL seeds, wkrllc.com) → `GenerateKeywordHistoricalMetrics` (volume, competition, top-of-page bid micros) → score/rank by commercial+emergency intent → cluster into lean ad groups. Rate limit: 1 req/sec per customer ID; cache results (metrics refresh monthly).
- A full reusable handoff prompt exists (12 sections: exec summary → research method → findings table → structure → match types → negatives → RSA copy → assets → landing pages → bidding → 30-day plan → final priorities) with decision rules: "optimize for profitable conversions first, CPC second; don't pick keywords because they're cheap or high-volume; smaller list of better keywords."

---

## 3. SMS Campaigns & Compliance

### 10DLC campaign submission pack — FINAL
*(Roadside SMS Campaign — ChatGPT)*
- **Campaign name:** White Knight Roadside Customer Updates. **Use case:** Mixed (Customer Care + Account Notifications + Marketing).
- **Opt-in methods:** (1) web form checkbox (unchecked by default), (2) verbal consent during intake call, (3) text START.
- **Opt-in disclosure:** "By providing your mobile number, you agree to receive text messages from White Knight Roadside about service updates, dispatch status, estimates, invoices, and occasional promotions. Message frequency varies. Msg & data rates may apply. Reply STOP to opt out, HELP for help."
- **HELP response:** "White Knight Roadside: For help, call (503) 974-2741 or reply with your question. Msg frequency varies. Msg & data rates may apply. Reply STOP to opt out."
- **STOP response:** "White Knight Roadside: You have been unsubscribed and will no longer receive messages. Reply START to re-subscribe."
- **Sample messages (9):** opt-in confirmation; dispatch update w/ ETA; ETA change; estimate ready ("$185. Reply APPROVE to authorize or call…"); work-in-progress; invoice notice ("Reply PAY for payment link"); payment receipt; appointment reminder; promotion (winter battery check, "Reply INFO… Reply STOP to opt out").
- Tip noted: a customer-care-only version (no marketing language) gets easier first-time 10DLC approval.

### Verbal authorization scripts — read after verbal opt-in
*(SMS Authorization Script — ChatGPT)*
- **Service/dispatch updates:** "By giving me your mobile number and saying yes, you agree to receive SMS updates from White Knight Roadside about service updates: dispatch, ETAs, job status, estimates/invoices. Message frequency may vary. Standard message and data rates may apply. Reply STOP to opt out and HELP for help."
- **Marketing texts:** same plus "Consent is not a condition of purchase." **Important:** promotional/autodialed marketing texts generally require *prior express written consent* under TCPA — do not rely on verbal-only consent for marketing.
- Always send a confirmation SMS immediately after verbal opt-in: "You have agreed to receive SMS updates from White Knight Roadside. Msg freq may vary. Std msg & data rates apply. Reply STOP to opt out, HELP for help."

---

## 4. SEO Plan for wkrllc.com
*(Website SEO improvement plan — Claude; full proposal was drafted to an MD file with 90-day rollout)*

Already strong: clean URLs, keyword-targeted titles, matching H1s, breadcrumbs, FAQ blocks, city landing pages, internal links, NAP consistency, mobile layout, GBP link.

**Priority 1 — technical (week one):** verify robots.txt + sitemap.xml, submit to GSC/Bing; add JSON-LD schema — LocalBusiness (`AutoRepair`/`EmergencyService`) on homepage, `Service` + `offers` (price) per service page, `FAQPage` where Q&A exists, `AggregateRating`, `BreadcrumbList`; canonicals + OG/Twitter tags; pick one of wkrllc.com vs www and 301 the other; fix blank copyright year.

**Priority 2 — content gaps:** dedicated unique page for every listed city (Beaverton, Gresham, Lake Oswego, Tigard, Hillsboro, Milwaukie, Tualatin, West Linn, Oregon City, Clackamas, Vancouver — 600+ words each, no near-duplicates); add missing service pages actually offered (battery replacement vs jump start, "car won't start diagnostic", motorcycle/RV if applicable, and a "why we don't tow + who we recommend" page); blog 2 posts/month on informational queries ("signs your battery is dying", "locked out of your car in Oregon"); dedicated /reviews page with 15–20 reviews + schema.

**Priority 3 — on-page:** unique 140–160 char meta descriptions with phone + hook (template: "Need [service] in [city]? White Knight Roadside offers $[price] flat rate, 30-min response, 24/7. Call (503) 764-3154."); real photos with descriptive filenames + alt text; "Nearby service areas" cross-link block on each city page; fix H-tag hierarchy (one H1, no orphan H4s).

**Priority 4 — off-site/local:** GBP weekly posts, 10+ photos/month, respond to every review <24h, seed Q&A; citations (Yelp, Angi, Nextdoor, BBB, Apple Maps, Bing Places, Waze, YP) with exact NAP match; backlinks via local news, sponsorships, referral partnerships with towing companies/body shops/dealerships.

**Priority 5 — measurement:** GSC, Bing WMT, GA4, call tracking (CallRail-style) to attribute SEO vs GBP vs direct.

---

## 5. $35 Video Consultation Concept
*(35 Video Consultation Strategy — ChatGPT)*

**Positioning — FINAL:** "Virtual Mechanic Assessment" — $35, 15 minutes, live video; **$35 credited toward any WKR service booked within 24 hours** (turns "pay to talk" into "expert assessment applied to the repair"). Treat it as lead generation/qualification first, revenue second; upsell path into the $125/hr mobile mechanic service.

- Works well remotely: no-start diagnosis, battery vs alternator, warning lights, tire damage / "can I drive on this?", fluid leaks, second opinions on shop estimates, pre-purchase walk-throughs, DIY guidance. Poor fit: lockouts, fuel delivery, stranded-on-freeway emergencies, scan-tool-dependent or intermittent electrical issues.
- **Nationwide angle:** as a remote diagnostic service it isn't limited to the Portland dispatch radius — sells expertise, fills idle time between the 1–2 local jobs/day.
- **Validation-first rollout (decided):** Stage 1 — no platform: Square payment + Google Meet link by SMS, run it manually (~$0 cost). Stage 2 — after 10–20 paid consults: booking/payment page + tracking in the app. Stage 3 — after consistent demand: integrated WebRTC rooms, using **Telnyx STUN/TURN** (stun.telnyx.com:3478 / turn.telnyx.com:3478) since WKR already uses Telnyx for SMS; self-hosted coturn on a $5–10/mo VPS is the alternative. STUN alone connects ~70–90% of calls; TURN relay gets ~99% (carrier-grade NAT affects cellular too, not just wifi).
- Required disclaimer: "This service provides professional guidance and diagnostic assistance based on information available during the consultation. It is not a guarantee of repair outcome and does not replace an in-person inspection."
- Scheduled (not on-demand) consults recommended for a solo operator.

---

## 6. Fleet Outreach — Tire Proposal Email (FINAL)
*(Fleet Tire Proposal Email — ChatGPT)*

Final pricing structure after several revisions:
- **Call-Out / Dispatch Fee: $65** — flat per visit; multiple vehicles/tires serviced in the same dispatch without compounding charges (the key selling point).
- **Tire Plug Repair: $30**
- **Tire Patch Repair: $50**
- **Tire Replacement (Labor): $80** — includes mount and balance
- **Replacement Tire Cost: $50–$200** depending on size/type/availability
- **Tire Disposal: $7/tire** if required

Email framing: mobile, on-site, fast-response service that minimizes fleet downtime and eliminates towing; simple transparent pricing that scales; emphasizes consistency, communication, accountability; closes by asking to discuss fleet size and service expectations. (Full final email text lives in the source conversation, id 27468.)

---

## 7. LinkedIn Strategy
*(Company LinkedIn Strategy — ChatGPT)*
- Verdict: LinkedIn is "nice-to-have credibility," **not** a growth lever for roadside; inbound calls come from GBP, website, Apple Maps/Bing Places, Yelp/Nextdoor/Facebook.
- If creating a page: personal profile required as admin; add name, URL, logo, tagline, location, services; short About (what/where + 2–3 services + licensed/insured trust signals); post 3 starter posts (service-area map, services list, "how it works") so it isn't empty.

---

## 8. Customer Growth Playbook (organic)
*(Increase roadside customers — ChatGPT)*

**Top levers:** (1) GBP optimization — correct service area, 20+ photos, 1–2 posts/week, messaging on, chase reviews toward 50+; (2) answer speed — "Roadside assistance, this is Jason — what kind of problem do you have today?", callback within 2 min; (3) B2B relationships — repair shops, used car lots, rentals, dealer service centers, HVAC/plumber fleets, delivery couriers; 3–5 business clients = steady weekly work (discounted fleet jumpstarts, priority response, monthly billing); (4) differentiators vs AAA — mobile battery test/replacement (20–30% battery markup), battery delivery, alternator testing, OBD2 scans, spare-tire transport; (5) Google Local Services Ads (Google Guaranteed) called the highest-ROI paid channel; Facebook Marketplace listings + local groups; (6) review-request text: "If I took care of you today, could you drop a quick review on Google? It helps me keep my prices low."; (7) referral ask: "tell them to ask for Jason at WKR"; (8) optional WKR Loyalty Plan $20/yr — $10 off every call, free battery test, priority response.

**Weekly routine (condensed):** Daily 10–20 min — GBP check + one micro-post ("Another jumpstart in NE Portland"), review ask after each job, scan Facebook groups for stranded-driver posts. Mon — one site/blog update + review last week's lead sources. Tue — renew Facebook Marketplace listings. Wed — contact 1–2 local businesses. Thu — proof-of-work photo post. Fri — $10–20 weekend boost (10-mi radius). Sat — pricing review. Sun — restock/clean vehicle, recharge jump packs.

---

## 9. Competitive Research (software landscape)
*(Competitive Research Report — ChatGPT; researched competitors to the internal WKR app, useful for positioning and customer-experience benchmarks)*

- **Strategic target chosen:** not a Towbook/Jobber clone but "a professional roadside/mobile-mechanic operating system for independent operators moving a job call → estimate → approval → field work → proof → invoice → payment → accounting with almost no duplicate entry."
- **Direct competitors:** Towbook (roadside-native depth; transparent pricing $109/$209/$319/$429 per month tiers; geocoded photos, customer-location links, job-progress texts, proof of service, Square/QuickBooks); Dispatch Anywhere/Autura (dispatcher productivity, 250+ reports, embedded TowPay); Agero/Swoop/Urgently (industry direction: public mobile-web intake links, duplicate-job prevention, customer status pages, ETA/dispatch intelligence).
- **Adjacent:** Jobber (polished customer-facing quote→approve→pay flow), Housecall Pro (voice-to-invoicing, customer portal), Workiz (lead-source tracking, built-in calls/SMS — relevant to attributing Google Ads calls), FieldPulse (job-linked parts/inventory), Shopmonkey/Tekmetric/AutoLeap/Shop-Ware (repair-order workflow, digital inspections, profitability reporting).
- **Marketing-relevant patterns to emulate:** every winner puts the customer on a link (intake form, estimate approval, status page, payment); field proof (geocoded photos, signatures) doubles as chargeback defense; review-request links post-payment (Towbook/Tekmetric/AutoLeap); track job source (Google Ads / repeat / referral / broker) as a first-class field; report "customer source" and margin by service.
- Open-source/GitHub: no mature roadside competitor exists; Dolibarr cited as the best PHP/MySQL architecture reference, Invoice Ninja for client-facing invoicing polish.
- The conversation also produced a solo-operator app dev plan (out of marketing scope; captured in app-related bins).

---

## Quick-Reference Decisions
| Item | Final decision | Source |
|---|---|---|
| Tagline | "We Answer the Call" | Tagline for White Knight Roadside |
| Ads campaign | "Roadside Assistance — Non-Towing", 7 intent-based ad groups, Phrase+Exact, Presence targeting | Roadside Ad Campaign Strategy |
| Ad format | RSAs + call assets (call-only ads die Feb 2026/2027) | Multiple |
| Bidding | Manual CPC + eCPC at launch, tiered by intent; tCPA after ~20 conversions | Google Ads API Roadside Assistance Strategy |
| Key failure lesson | $150/day, 0 impressions = bids below first-page range; verify top-of-page bid columns | Keyword Planner Bid Ranges Explained |
| SMS | 10DLC mixed-use campaign pack + verbal-consent scripts; written consent required for marketing texts | Roadside SMS Campaign; SMS Authorization Script |
| Fleet tires | $65 callout / $30 plug / $50 patch / $80 replace (mount+balance incl.) / tire $50–200 / $7 disposal | Fleet Tire Proposal Email |
| Video consult | $35, 15 min, credited toward service within 24h; validate manually via Square + Meet before building | $35 Video Consultation Strategy |
| LinkedIn | Low priority; GBP and local channels first | Company LinkedIn Strategy |
