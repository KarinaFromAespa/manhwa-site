# Manhwa sources

- **Date:** 2026-11-23
- **Source repo:** https://github.com/keiyoushi/extensions-source @ `582615a3` (2026-11-23)
- **Method:** sparse blobless clone of the repo; every source's `build.gradle.kts` was parsed for its display name, language and base URL (primary URL listed; mirrors noted). Themes come from the `theme = "..."` declaration in the same file. Login signals were checked in each candidate's Kotlin sources (login implementations, auth checks, login-gated endpoints) and cross-checked against knowledge of each site.
- **Total sources scanned:** 1727 (all languages; `ja` and `zh` excluded upfront as manga / manhua)
- **Manhwa sites listed:** 200 (free, no login: 186; login required: 14; adult: 64)
- **Breakdown by language (all):** all: 8, ar: 7, en: 93, es: 17, fr: 2, id: 9, it: 1, ko: 13, pt-BR: 22, th: 6, tr: 19, vi: 3
- **Breakdown by language (free, no login):** all: 6, ar: 7, en: 90, es: 15, fr: 2, id: 9, it: 1, ko: 13, pt-BR: 17, th: 6, tr: 17, vi: 3
- **Breakdown by language (login required):** all: 2, en: 3, es: 2, pt-BR: 5, tr: 2

## Rules used

- **Manhwa scope:** all Korean (`ko`) sources, plus English and other-language sources whose site is primarily manhwa (scanlators, aggregators and toon sites). Manga-first and manhua-first aggregators, novel sites, anime/video sites, single-title manga readers and local self-hosted servers are out. `(unoriginal)` clones are out.
- **Paywalled sites are dropped entirely:** official platforms with paid chapters/episodes or subscriptions (Naver Webtoon, RidiBooks, Tappytoon, Tapas, Toomics, Manta, INKR, Coolmic, K Manga, Kodansha, BookWalker, Manga UP!, MangaToon, Honeytoon, Webtoons.com, Comikey, GlobalComix, Webcomics app, Heytoon). Sites with a free catalogue plus a paid/premium subset are kept, with a note.
- **Access:** `free` = fully usable without an account; `login` = free after creating/logging into an account (no payment). When access could not be determined from code or knowledge, the entry is in the login-required list with an "unknown — verify" note rather than guessed as free.
- **Adult:** adult (18+) manhwa is included like any other. `adult` is true when the extension marks the source NSFW or the site is adult-only by name/content (e.g. Manhwa18, Pornhwa). Mixed SFW/NSFW sites are not flagged adult unless they are adult-only.
- **Confidence:** `confirmed` = known manhwa site; `likely` = believed manhwa-first from name, theme or site, but not verified. "Likely" entries should be spot-checked before being wired into the reader.
- **Liveness not verified:** sites were not probed; domains (especially the numbered `*.com` mirrors of Korean raw sites) rotate and some entries may be down or moved.
- **Duplicates:** deduped by site URL; alternate domains of the same service are noted, not listed twice.

## Free — no login (186)

| Name | Lang | Site | Theme | Notes |
| --- | --- | --- | --- | --- |
| Manhwa-raw | all | https://manhwa-raw.com | madara | likely; adult |
| Manhwa18.cc | all | https://manhwa18.cc | yiimadara | likely; adult |
| ManhwaClub.net | all | https://manhwaclub.net | madara | likely; adult |
| SayManhwa | all | https://saymanhwa.net | — | likely; same group as New Manhwa |
| ToonLook | all | https://toonlook.com | — | likely; adult |
| ToonSpace | all | https://toon.space | — | likely; adult |
| Arab Toons | ar | https://arabtoons.net | — | likely; adult |
| ArabManhwa | ar | https://arabmanhwa.com | — | likely; adult |
| AriaToon | ar | https://ariatoon.com | — | likely |
| Empire Webtoon | ar | https://webtoonempire-bl.com | madara | likely; adult; BL |
| Murim | ar | https://murim-manga.blogspot.com | zeistmanga | likely; murim manhwa |
| Paradise BL | ar | https://paradise-bl.com | madara | likely; adult; BL |
| Peach BL | ar | https://peach-bl.com | — | likely; BL |
| Akaza Scans | en | https://akazascans.org | mangathemesia | likely |
| Aqua Manga | en | https://aquareader.org | madara | likely |
| Asmodeus Scans | en | https://asmotoon.com | keyoapp | likely |
| Asura Scans | en | https://asurascans.com | — | confirmed; some chapters are premium/locked — free chapters available |
| Aunt Manhwa | en | https://auntmanhwa.com | madara | likely; adult |
| Baetoon | en | https://baetoon.com | — | likely |
| Comic Asura | en | https://comicasura.net | mangathemesia | likely |
| Comix | en | https://comix.to | — | confirmed; the site this list is for; also: https://comix.ws |
| Comizy | en | https://comizy.io | mangak | likely |
| DesireScans | en | https://desirescans.com | — | likely; adult |
| DragonTea | en | https://dragontea.ink | madara | confirmed |
| Elf Toon | en | https://elftoon.net | vinetheme | likely |
| EZmanga | en | https://ezmanga.org | ezmanhwa | likely; ezmanhwa theme |
| Flame Comics | en | https://flamecomics.xyz | — | confirmed; Flame Scans |
| Genz Toons | en | https://genztoons.org | keyoapp | likely |
| GingeRTooN | en | https://gingertoon.com | madara | likely; adult |
| Hive Scans | en | https://hivetoons.org | iken | likely |
| Kaizen Scan | en | https://kaizenscan.com | keyoapp | likely; adult |
| Komix | en | https://komix.to | — | likely |
| LGBToon | en | https://lgbtoon.com | — | likely; adult |
| Lua Scans | en | https://luacomic.org | heancms | likely |
| Luna Toons | en | https://lunatoons.net | keyoapp | likely |
| LustToon | en | https://lustoon.com | — | likely; adult |
| LuxManga | en | https://luxmanga.net | — | likely; some chapters cost coins |
| Magus Manga | en | https://magustoon.org | iken | likely |
| Manhwa Comics | en | https://manhwacomics.com | madara | likely; adult |
| Manhwa Reads | en | https://manhwareads.com | madara | likely |
| Manhwa Toon | en | https://www.manhwatoon.me | madara | likely |
| Manhwa18 | en | https://manhwa18.com | — | likely; adult |
| Manhwa18.net | en | https://manhwa18.net | — | likely; adult |
| Manhwa18.today | en | https://www.manhwa18.today | madara | likely; adult |
| Manhwa18Dash | en | https://manhwa18dash.com | — | likely; adult |
| Manhwa18TX | en | https://manhwa18tx.com | yiimanga | likely; adult |
| Manhwa210 | en | https://manhwa210.com | — | likely; adult |
| Manhwa68 | en | https://manhwa68.com | madara | likely; adult |
| ManhwaBuddy | en | https://manhwabuddy.com | yiimanga | likely; adult |
| Manhwaddict | en | https://manhwaddict.com | yiimanga | likely |
| ManhwaDen | en | https://www.manhwaden.com | madara | likely; adult |
| ManhwaGalaxy | en | https://manhwagalaxy.com | yiimanga | likely |
| ManhwaH | en | https://manhwah.com | — | likely; adult |
| ManhwaHub.to | en | https://manhwahub.to | madara | likely; adult |
| Manhwalike | en | https://manhwalike.com | — | likely; adult |
| ManhwaManhua | en | https://manhwamanhua.com | madara | likely; adult; also hosts manhua |
| ManhwaNex | en | https://manhwanex.com | madara | likely |
| ManhwaOnline | en | https://manhwaonline.org | yiimanga | likely |
| ManhwaRead | en | https://manhwaread.com | — | likely; also: https://manhwaread.org |
| Manhwatop | en | https://manhwatop.com | madara | likely |
| ManhwaUS | en | https://manhwaus.net | yiimadara | likely; adult |
| ManhwaZ | en | https://manhwaz.cc | — | likely |
| ManhwaZone | en | https://manhwazone.com | — | likely |
| MeiToon | en | https://meitoon.org | keyoapp | likely |
| MurimScan | en | https://www.murimscans.site | zeistmanga | likely; murim manhwa |
| Myth Toons | en | https://mythtoons.org | — | likely; adult |
| New Manhwa | en | https://saymanhwa.com | — | likely; same group as SayManhwa |
| Nuvia Toon | en | https://nuviatoon.org | — | likely; some chapters cost coins to unlock |
| Omega Scans | en | https://omegascans.org | heancms | likely; adult |
| Orion Scans | en | https://orion-scans.com | iken | confirmed |
| Pornhwa.me | en | https://pornhwa.me | — | likely; adult |
| Pornhwa18 | en | https://pornhwa18.com | — | likely; adult |
| PornhwaDB Reader | en | https://pornhwareader.com | — | likely; adult |
| Pornhwaz | en | https://www.pornhwaz.com | madara | likely; adult |
| Qi Scans | en | https://qimanga.com | ezmanhwa | likely; ezmanhwa theme |
| Rackus | en | https://rackusreads.com | mangathemesia | likely |
| Raven Scans | en | https://ravenscans.org | mangathemesia | confirmed |
| Read Solo Leveling Manga Manhwa Online | en | https://ww4.readsololeveling.org | mangacatalog | likely; single title (Solo Leveling) |
| Read The Beginning After The End Manga Online | en | https://w22.thebeginningaftertheendmanga.com | solomanga | likely; single title (The Beginning After The End) |
| S2Manga | en | https://s2read.com | madara | likely |
| Siren Scans | en | https://sirenscans.com | keyoapp | likely |
| Temple Scan | en | https://templetoons.com | — | confirmed; adult; some chapters are premium/locked |
| The Girl from Random Chatting Manga Online | en | https://thegirlfromrandomchatting.com | — | likely; single title (The Girl from Random Chatting) |
| Thunder Scans | en | https://en-thunderscans.com | mangathemesia | likely |
| TimelessToons | en | https://timelesstoons.org | keyoapp | likely |
| Toon Hub | en | https://toon-hub.com | — | likely; some episodes need VIP membership or coins |
| ToonGod | en | https://www.toongod.org | madara | likely |
| ToonGrab | en | https://toongrab.com | — | likely |
| Toonizy | en | https://toonizy.com | madara | likely |
| Toonkotsu | en | https://toonkotsu.com | — | likely; chapters need unlocking on the site |
| Toonz/Toonx | en | https://toonz.to | — | likely |
| TopManhua.fan | en | https://www.topmanhua.fan | madara | likely; name says manhua but site is manhwa-focused — verify |
| TopManhua.net | en | https://topmanhua.net | madara | likely; name says manhua but site is manhwa-focused — verify |
| Tower Of God Manga Online | en | https://w85.thetowerofgod.com | mangacatalog | likely; single title (Tower of God) |
| Violet Scans | en | https://violetmanga.com | mangathemesia | likely |
| Vortex Scans | en | https://vortexscans.org | iken | likely |
| WebtoonClub | en | https://webtoonclub.com | — | likely |
| WebtoonLib | en | https://webtoonlib.to | — | likely |
| WebtoonScan | en | https://webtoonscan.org | madara | likely; adult |
| WebtoonXYZ | en | https://www.webtoon.xyz | madara | likely |
| Wildtoon | en | https://wildtoon.com | mangathemesia | likely; adult |
| Zinmanga | en | https://mangazin.org | madara | confirmed; same group as Zinmanga.net |
| Zinmanga.net | en | https://www.zinmanga.net | madara | confirmed; same group as Zinmanga |
| AKAYA | es | https://akaya.io | — | likely |
| Catoons | es | https://cattoons.org | — | likely; adult |
| Emperor Scan | es | https://imperiomanhwa.com | imperiomanhwa | confirmed |
| LeerManhwas | es | https://leermanhwas.com | yiimanga | likely; adult |
| Manhwa-Latino | es | https://manhwa-latino.com | madara | likely; also: https://manhwa-es.com |
| ManhwaOnline | es | https://manhwa-online.com | — | likely; adult |
| ManhwaWeb | es | https://manhwaweb.com | — | likely |
| Mundo Manhwa | es | https://mundomanhwa.com | madara | likely; adult |
| RichtoScan | es | https://r1.richtoon.top | madara | likely; premium chapters exist (excluded by the extension) |
| Submanhwa | es | https://submanhwa.com | — | likely; adult |
| Taurus Fansub | es | https://lectortaurus.com | madara | likely; paid chapters exist (filterable) |
| TmoManhwa | es | https://tmomanhwa.com | yiimanga | likely; adult |
| Toon-es | es | https://toon-es.com | madara | likely; adult |
| Traducciones Moonlight | es | https://traduccionesmoonlight.com | moonlighttl | likely |
| Ver Manhwas | es | https://vermanhwa.com | madara | likely; adult |
| Banchan Scan | fr | https://banchanscan.fr | — | likely |
| Toon FR | fr | https://toonfr.com | madara | likely; adult |
| CosmicScans | id | https://04.cosmicscans.to | — | likely |
| Holotoon | id | https://holodek.run | — | likely |
| Manhwa Indo | id | https://www.manhwaindo.my | — | likely |
| Manhwa List | id | https://m1.manhwalist.my.id | mangathemesia | likely |
| ManhwaDesu | id | https://manhwadesu.rest | mangathemesia | likely; adult |
| ManhwaLand.mom | id | https://02.manhwaland.land | mangathemesia | likely; adult |
| Soul Scans | id | https://v1.soulscans.asia | loneseal | likely |
| Tooncubus | id | https://www.tooncubus.top | zeistmanga | likely; adult |
| VoraToon | id | https://voratoon.co | — | likely |
| Phoenix Scans | it | https://www.phoenixscans.com | pizzareader | likely |
| 11toon | ko | https://www.11toon.com | — | confirmed |
| BlackToon | ko | https://blacktoon426.com | — | confirmed; KR: 블랙툰 |
| Cookmana | ko | https://cookmana12.com | — | confirmed; adult |
| DDToon | ko | https://ddtoon33.com | — | confirmed; KR: 뚜툰 |
| GoodToon | ko | https://www.goodtoon010.com | madara | confirmed |
| Jjaptoon | ko | https://www.jjaptoon008.com | — | confirmed; KR: 짭툰 |
| Manga24 | ko | https://ko.manga24.net | madara | confirmed; adult; KR: 망가24 |
| NameeToon | ko | https://namee134.asia | — | confirmed; KR: 나미툰 |
| Newtoki (SBXH) | ko | https://sbxh9.com | — | confirmed; Newtoki mirror |
| NewXToon | ko | https://newxtoon5.com | — | confirmed |
| RawDEX | ko | https://rawdex.net | — | confirmed; adult |
| Toonkor | ko | https://toonkor0.org | — | confirmed |
| Wolf.com | ko | https://wfwf541.com | — | confirmed; KR: 늑대닷컴 - 웹툰 |
| Astratoons | pt-BR | https://new.astratoons.com | — | likely |
| Blitz Toons | pt-BR | https://blitztoons.com | — | likely |
| Capitoons | pt-BR | https://capitoons.com | mangawork | likely |
| Cerise Scan | pt-BR | https://loverstoon.net | — | likely |
| Ego Toons | pt-BR | https://egotoons.com | — | likely |
| Funktoon | pt-BR | https://funktoon.com | — | likely |
| Hipertoon | pt-BR | https://hipertoon.com | hiper | likely |
| KivaraToons | pt-BR | https://kivaratoons.com | — | likely |
| Lycan Toons | pt-BR | https://lycantoons.com | — | likely |
| Mango Toons | pt-BR | https://mangotoons.com | — | likely |
| Manhastro | pt-BR | https://manhastro.net | — | likely; adult |
| Nexus Toons | pt-BR | https://nexustoons.com | — | likely |
| Point Zero Toons | pt-BR | https://kitsuneyako.com | — | likely |
| Risentoons | pt-BR | https://risentoons.xyz | — | likely |
| Tia Manhwa | pt-BR | https://tiamanhwa.com | madara | likely; adult |
| ToonLivre | pt-BR | https://toonlivre.net | — | likely |
| Vegitoons | pt-BR | https://vegitoons.black | greenshit | likely |
| ManhwaBreakup | th | https://www.manhwabreakup.com | madara | likely |
| ReadToon | th | https://readtoon.com | — | likely |
| ReaperTrans | th | https://reapertrans.com | mangathemesia | likely |
| ToonHunter | th | https://toonhunter.com | mangathemesia | likely; adult |
| Toonjai | th | https://toonjai.com | — | likely |
| WhyToon | th | https://whytoon.com | — | likely; some chapters are premium |
| AeroToon | tr | https://aerotoon.vercel.app | — | likely |
| AfterToon | tr | https://aftertoon.com | — | likely |
| Gaiatoon | tr | https://gaiatoon.com | mangathemesia | likely |
| Kairatoon | tr | https://kairatoon.net | — | likely; some content is premium |
| Koreli Manga | tr | https://korelimanga.com | initmanga | likely; Korean (Koreli) manga |
| Koreli Scans | tr | https://www.nabicix.com | mangathemesia | likely; Korean (Koreli) scans |
| Luna Scans | tr | https://tuhafscans.com | madara | likely; adult |
| Merlin Scans | tr | https://merlintoon.net | initmanga | likely |
| OkuToon | tr | https://okutoon.net | — | likely |
| Opiatoon | tr | https://opiatoon.shop | madara | likely; adult |
| Seitoon | tr | https://seitoon.com.tr | — | likely |
| SummerToon | tr | https://summertoons.org | mangathemesia | likely; some chapters paywalled |
| TonizuToon | tr | https://tonizu.top | madara | likely |
| Toontaku | tr | https://toontaku.com | — | likely |
| Webtoon Hatti | tr | https://webtoonhatti.club | madara | likely |
| WebtoonOku | tr | https://webtoonoku.org | — | likely |
| Zenith Scans | tr | https://zenithscans.com | mangathemesia | likely |
| KhoManhwa | vi | https://khomanhwa.com | — | likely; adult |
| LoppyToon | vi | https://loppytoonn.com | — | likely |
| VietManhwa | vi | https://vietmanhwa.org | — | likely; adult |

## Login required (14)

| Name | Lang | Site | Theme | Notes |
| --- | --- | --- | --- | --- |
| Lunar Manga | all | https://lunarx.to | — | likely; login needed; chapters unlock with free ad points |
| Taddy INK (Webtoons) | all | https://taddy.org | — | likely; access unknown — verify |
| Dashtoon | en | https://dashtoon.com | — | likely; official platform; access unknown — verify |
| Toonily | en | https://toonily.com | madara | likely; login required to read; freemium, some chapters may need subscription — verify |
| ToonTop | en | https://toontop.io | mangak | likely; adult; Toonily mirror/alternate; login required to read |
| Catharsis World | es | https://newcatharsis.dig-it.info | catharsis | likely; some chapters require login on the site |
| ManhwaShot | es | https://manhwashot.lat | — | likely; some chapters require login |
| Azuretoons | pt-BR | https://azuretoons.com | — | likely; requires login (auto-login with stored credentials) |
| Bladetoons | pt-BR | https://bladetoons.com | mangotheme | likely; requires login (code: requiresLogin) |
| Hunters Scans | pt-BR | https://readhunters.xyz | madara | likely; requires login via WebView |
| Mediocre Toons | pt-BR | https://laranjatoons.com | — | likely; requires login (email/password in extension prefs) |
| ToonBr | pt-BR | https://beta.toonbr.com | — | likely; requires login (email/password in extension prefs) |
| Webtoonia | tr | https://webtoonia.fun | madara | likely; adult; some content requires login |
| Xiaoaitoon | tr | https://xiaoaitoon.org.tr | — | likely; adult; some chapters locked unless logged in |

## Dropped as paywalled (official platforms)

Naver Webtoon (`comic.naver.com`), RidiBooks (`ridibooks.com`), Tappytoon, Tapas, Toomics, Manta, INKR, Coolmic, K Manga, Kodansha, BookWalker, Manga UP!, MangaToon, Honeytoon, Webtoons.com (LINE Webtoon), Comikey, GlobalComix, Webcomics app, Heytoon.

## Excluded for other reasons

- `(unoriginal)` clones (Asuratoons, ToonGod.cc, Coffee Manga, KaliScan, YaoiScan.me, Qtoon, Hentai20.ink, LectorTMO clones, ZonaTMO clones, Comick.live, etc.)
- Manga-first / manhua-first aggregators (MangaDex, Mangakakalot, Manganelo, MangaFire, MangaLib, Kiryuu, NetTruyen clones, CuuTruyen, etc.)
- Novel sites (WebNovel, J-Novel, WuxiaWorld, NovelCool), anime/video sites, imageboards/galleries, local self-hosted servers (Komga, Kavita-style, Mango, LANraragi, Jellyfin).
- Japanese (`ja`, 153 sources) and Chinese (`zh`, 82 sources) sources in full — manga / manhua by definition.
