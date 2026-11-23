# Manhwa & manhua sources

- **Date:** 2026-11-23
- **Source repo:** https://github.com/keiyoushi/extensions-source @ `582615a3` (2026-11-23)
- **Method:** sparse blobless clone of the repo; every source's `build.gradle.kts` was parsed for its display name, language and base URL (primary URL listed; mirrors noted). Themes come from the `theme = "..."` declaration in the same file. Login signals were checked in each candidate's Kotlin sources (login implementations, auth checks, login-gated endpoints) and cross-checked against knowledge of each site. Round 2 (2026-11-23) re-evaluated the previously excluded sets: all 82 Chinese sources, mixed aggregators, and scanlator groups whose focus was undetermined. Mixed sites were kept if their code exposes a manhwa/manhua type filter or genre.
- **Total sources scanned:** 1727 (all languages; `ja` excluded upfront as manga)
- **Sites listed:** 508 (free, no login: 486; login required: 22; adult: 107; English: 182)
- **By kind (all):** manhua: 70, manhwa: 229, mixed: 209
- **By kind (free, no login):** manhua: 69, manhwa: 214, mixed: 203
- **Breakdown by language (all):** all: 28, ar: 12, en: 182, es: 44, fr: 11, id: 34, it: 1, ko: 13, pt-BR: 41, ru: 10, th: 13, tr: 34, uk: 4, vi: 13, zh: 64, zh-Hans: 2, zh-Hant: 2
- **Breakdown by language (free, no login):** all: 26, ar: 12, en: 179, es: 42, fr: 11, id: 33, it: 1, ko: 13, pt-BR: 35, ru: 10, th: 13, tr: 30, uk: 4, vi: 13, zh: 61, zh-Hans: 2, zh-Hant: 1
- **Breakdown by language (login required):** all: 2, en: 3, es: 2, id: 1, pt-BR: 6, tr: 4, zh: 3, zh-Hant: 1

## Rules used

- **Scope:** manhwa (Korean) and manhua (Chinese), plus mixed sites that hold manga + manhua + manhwa — mixed sites are kept as `mixed`. Priority is English: EN entries sort first in each table, but other languages are listed too.
- **Kind:** `manhwa` = Korean-origin titles; `manhua` = Chinese-origin titles; `mixed` = multi-origin aggregators (manga + manhwa + manhua). Best effort from language, theme, name and the site's own type/genre filters.
- **Paywalled sites are dropped entirely:** official platforms with paid chapters/episodes or subscriptions (Naver Webtoon, RidiBooks, Tappytoon, Tapas, Toomics, Manta, INKR, Coolmic, K Manga, Kodansha, BookWalker, Manga UP!, MangaToon, Honeytoon, Webtoons.com, Comikey, GlobalComix, Webcomics app, Heytoon, Bilibili Manga, Tongli, Toptoon, Kuaikan Manhua, Tencent Comics, iQiyi, BookWalker Taiwan). Sites with a free catalogue plus a paid/premium subset are kept, with a note.
- **Access:** `free` = fully usable without an account; `login` = free after creating/logging into an account (no payment). When access could not be determined from code or knowledge, the entry is in the login-required list with an "unknown — verify" note rather than guessed as free.
- **Adult:** adult (18+) manhwa/manhua is included like any other. `adult` is true when the extension marks the source NSFW or the site is adult-only by name/content (e.g. Manhwa18, Pornhwa). Mixed SFW/NSFW sites are not flagged adult unless they are adult-only.
- **Confidence:** `confirmed` = known manhwa/manhua/mixed site; `likely` = believed in-scope from name, theme, site or code, but not verified. "Likely" entries should be spot-checked before being wired into the reader.
- **Liveness not verified:** sites were not probed; domains (especially the numbered `*.com` mirrors of Korean raw sites) rotate and some entries may be down or moved.
- **Duplicates:** deduped by site URL; alternate domains of the same service are noted, not listed twice.

## Free — no login (486)

| Name | Lang | Site | Theme | Notes |
| --- | --- | --- | --- | --- |
| Akaza Scans | en | https://akazascans.org | mangathemesia | likely; manhwa |
| AllManga | en | https://mkissa.to | — | likely; mixed |
| AP Comics | en | https://apcomics.org | madara | likely; manhwa; adult; adult |
| Aqua Manga | en | https://aquareader.org | madara | likely; mixed |
| Arena Scans | en | https://arenascan.com | mangathemesia | likely; manhwa; scanlator; focus not verified in code |
| Asmodeus Scans | en | https://asmotoon.com | keyoapp | likely; manhwa |
| Aster Scans | en | https://asterscans.com | mangathemesia | likely; manhwa; scanlator; focus not verified in code |
| Asura Scans | en | https://asurascans.com | — | confirmed; mixed; some chapters are premium/locked — free chapters available |
| Athrea Scans | en | https://athreascans.com | mangathemesia | likely; manhwa; scanlator; focus not verified in code |
| Atsumaru | en | https://atsu.moe | — | likely; mixed |
| Aunt Manhwa | en | https://auntmanhwa.com | madara | likely; manhwa; adult |
| Baetoon | en | https://baetoon.com | — | likely; manhwa |
| Bbato | en | https://bato1.co.uk | — | likely; mixed; Bato.to mirror |
| Chikari | en | https://chikari.moe | — | likely; mixed |
| Comic Asura | en | https://comicasura.net | mangathemesia | likely; manhwa |
| ComicK Fanmade | en | https://comickfan.com | — | likely; mixed |
| Comivex | en | https://comivex.com | — | likely; mixed |
| Comix | en | https://comix.to | — | confirmed; mixed; the site this list is for; also: https://comix.ws |
| Comizy | en | https://comizy.io | mangak | likely; manhwa |
| DesireScans | en | https://desirescans.com | — | likely; manhwa; adult |
| Diva Scans | en | https://divascans.org | vinetheme | likely; manhwa; scanlator; focus not verified in code |
| DragonTea | en | https://dragontea.ink | madara | confirmed; manhwa |
| Drake Scans | en | https://drakecomic.net | vinetheme | likely; manhwa; scanlator; focus not verified in code |
| Elf Toon | en | https://elftoon.net | vinetheme | likely; manhwa |
| Eris Scans | en | https://erisscans.com | keyoapp | likely; manhwa; scanlator; focus not verified in code |
| Eva Scans | en | https://evascans.net | mangathemesia | likely; manhwa; scanlator; focus not verified in code; locked/coin chapters exist |
| EZmanga | en | https://ezmanga.org | ezmanhwa | likely; mixed; ezmanhwa theme |
| Flame Comics | en | https://flamecomics.xyz | — | confirmed; manhwa; Flame Scans |
| Genz Toons | en | https://genztoons.org | keyoapp | likely; manhwa |
| GingeRTooN | en | https://gingertoon.com | madara | likely; manhwa; adult |
| Goda | en | https://manhuascans.org | goda | likely; manhua; manhua scans |
| Grim Scans | en | https://grimscans.com | keyoapp | likely; manhwa; scanlator; focus not verified in code |
| Hentara | en | https://hentara.com | — | likely; manhwa; adult |
| Hijala Scans | en | https://en-hijala.com | iken | likely; manhwa; scanlator; focus not verified in code |
| Hive Scans | en | https://hivetoons.org | iken | likely; manhwa |
| Hyakuro Translations | en | https://hyakuro.net | — | likely; mixed |
| HYPERCOMIC | en | https://play.hypercomic.io | — | likely; mixed; webtoon episodes |
| Inkora | en | https://inkora.spacely.tech | — | likely; mixed |
| INKRUSH | en | https://inkrush.site | — | likely; mixed |
| Kaizen Scan | en | https://kaizenscan.com | keyoapp | likely; manhwa; adult |
| Kappa Beast | en | https://kappabeast.com | — | likely; mixed |
| Kayn Scans | en | https://kaynscans.com | vinetheme | likely; manhwa; scanlator; focus not verified in code |
| Ken Scans | en | https://kencomics.com | iken | likely; manhwa; scanlator; focus not verified in code |
| Kewn Scans | en | https://kewnscans.org | keyoapp | likely; manhwa; scanlator; focus not verified in code |
| Komix | en | https://komix.to | — | likely; mixed |
| KuraManga | en | https://kuramanga.com | — | likely; mixed |
| Lagoon Scans | en | https://lagoonscans.com | mangathemesia | likely; manhwa; scanlator; focus not verified in code |
| LGBToon | en | https://lgbtoon.com | — | likely; manhwa; adult |
| Lua Scans | en | https://luacomic.org | heancms | likely; manhwa |
| Luna Toons | en | https://lunatoons.net | keyoapp | likely; manhwa |
| LustToon | en | https://lustoon.com | — | likely; manhwa; adult |
| LuxManga | en | https://luxmanga.net | — | likely; mixed; some chapters cost coins |
| Madara Scans | en | https://madarascans.net | mangathemesia | likely; manhwa; scanlator; focus not verified in code |
| Magus Manga | en | https://magustoon.org | iken | likely; manhwa |
| Manga Dass | en | https://mangadass.com | yiimadara | likely; manhwa |
| Manga Demon | en | https://demonicscans.org | — | likely; mixed; Demonic Scans |
| Manga MTL | en | https://mtlmanga.org | manhuarm | likely; manhua |
| Manga-Bay | en | https://manga-bay.biz | — | likely; mixed |
| Manga.uno | en | https://manga.uno | — | likely; mixed |
| Mangack | en | https://mangack.com | — | likely; mixed |
| MangaCloud | en | https://mangacloud.org | — | likely; mixed |
| MangaDE | en | https://mangade.io | — | likely; mixed |
| MangaFox | en | https://fanfox.net | — | likely; mixed |
| MangaFox.fun | en | https://mangafox.fun | mangahub | likely; mixed; MangaFox mirror |
| Mangafreak | en | https://ww3.mangafreak.me | — | likely; manhwa |
| MangaGeko | en | https://www.mgeko.cc | — | likely; mixed |
| Mangahere | en | https://www.mangahere.cc | — | likely; mixed |
| MangaHere.onl | en | https://mangahere.onl | mangahub | likely; mixed; MangaHere mirror |
| MangaHome | en | https://www.mangahome.com | — | likely; mixed |
| MangaHub | en | https://mangahub.io | mangahub | likely; mixed |
| MangaK | en | https://mangak.io | mangak | likely; mixed; MangaK (mangak.io) |
| Mangakakalot | en | https://www.katamanga.com | mangabox | confirmed; mixed; well-known mixed aggregator |
| Mangakakalot.fun | en | https://mangakakalot.fun | mangahub | likely; mixed |
| MangaKatana | en | https://mangakatana.com | — | likely; mixed |
| MangaLix | en | https://mangalix.com | — | likely; manhwa |
| MangaMelon | en | https://mangamelon.com | — | likely; mixed |
| Manganato | en | https://www.natomanga.com | mangabox | confirmed; mixed; Manganato; well-known mixed aggregator |
| MangaNow | en | https://manganow.to | mangareader | likely; mixed |
| MangaPill | en | https://mangapill.com | — | likely; mixed |
| MangaReader.site | en | https://mangareader.site | mangahub | likely; mixed |
| Mangastash | en | https://mangastash.org | — | likely; mixed |
| MangaTaro | en | https://mangataro.org | mangataro | likely; mixed |
| Mangatown | en | https://www.mangatown.com | — | likely; mixed |
| Manhua Plus | en | https://manhuaplus.com | madara | likely; manhua |
| Manhua Zonghe | en | https://www.manhuazonghe.com | madara | likely; manhua; adult |
| ManhuaHot | en | https://manhuahot.com | madara | likely; manhua |
| Manhuanext | en | https://manhuanext.com | madara | likely; manhua; premium chapters exist (hidden by default) |
| ManhuaPlus.top | en | https://manhuaplus.top | — | likely; manhua |
| ManhuaTop | en | https://manhuatop.org | — | likely; manhua |
| ManhuaUS | en | https://manhuaus.com | madara | likely; manhua |
| Manhwa Comics | en | https://manhwacomics.com | madara | likely; manhwa; adult |
| Manhwa Reads | en | https://manhwareads.com | madara | likely; manhwa |
| Manhwa Toon | en | https://www.manhwatoon.me | madara | likely; manhwa |
| Manhwa18 | en | https://manhwa18.com | — | likely; manhwa; adult |
| Manhwa18.net | en | https://manhwa18.net | — | likely; manhwa; adult |
| Manhwa18.today | en | https://www.manhwa18.today | madara | likely; manhwa; adult |
| Manhwa18Dash | en | https://manhwa18dash.com | — | likely; manhwa; adult |
| Manhwa18TX | en | https://manhwa18tx.com | yiimanga | likely; manhwa; adult |
| Manhwa210 | en | https://manhwa210.com | — | likely; manhwa; adult |
| Manhwa68 | en | https://manhwa68.com | madara | likely; manhwa; adult |
| ManhwaBuddy | en | https://manhwabuddy.com | yiimanga | likely; manhwa; adult |
| Manhwaddict | en | https://manhwaddict.com | yiimanga | likely; manhwa |
| ManhwaDen | en | https://www.manhwaden.com | madara | likely; manhwa; adult |
| ManhwaGalaxy | en | https://manhwagalaxy.com | yiimanga | likely; manhwa |
| ManhwaH | en | https://manhwah.com | — | likely; manhwa; adult |
| ManhwaHub.to | en | https://manhwahub.to | madara | likely; manhwa; adult |
| Manhwalike | en | https://manhwalike.com | — | likely; manhwa; adult |
| ManhwaManhua | en | https://manhwamanhua.com | madara | likely; mixed; adult; also hosts manhua |
| ManhwaNex | en | https://manhwanex.com | madara | likely; manhwa |
| ManhwaOnline | en | https://manhwaonline.org | yiimanga | likely; manhwa |
| ManhwaRead | en | https://manhwaread.com | — | likely; manhwa; also: https://manhwaread.org |
| Manhwatop | en | https://manhwatop.com | madara | likely; manhwa |
| ManhwaUS | en | https://manhwaus.net | yiimadara | likely; manhwa; adult |
| ManhwaZ | en | https://manhwaz.cc | — | likely; manhwa |
| ManhwaZone | en | https://manhwazone.com | — | likely; manhwa |
| MeiToon | en | https://meitoon.org | keyoapp | likely; manhwa |
| Milftoon | en | https://milftoon.xxx | — | likely; manhwa; adult; adult webtoons |
| Mist Scans | en | https://mistscans.org | keyoapp | likely; manhwa; scanlator; focus not verified in code |
| MurimScan | en | https://www.murimscans.site | zeistmanga | likely; manhwa; murim manhwa |
| Myth Toons | en | https://mythtoons.org | — | likely; manhwa; adult |
| New Manhwa | en | https://saymanhwa.com | — | likely; manhwa; same group as SayManhwa |
| NexComic | en | https://nexcomic.com | — | likely; mixed |
| Nuvia Toon | en | https://nuviatoon.org | — | likely; mixed; some chapters cost coins to unlock |
| Nyx Scans | en | https://nyxscans.com | iken | likely; manhwa; scanlator; focus not verified in code |
| Omega Scans | en | https://omegascans.org | heancms | likely; manhwa; adult |
| Orion Scans | en | https://orion-scans.com | iken | confirmed; manhwa |
| OriYami | en | https://oriyami.com | — | likely; mixed |
| Paradise Scans | en | https://paradisescans.com | — | likely; manhwa; adult; premium chapters exist |
| Philia Scans | en | https://philiascans.org | — | likely; mixed; purchased chapters exist |
| Pornhwa.me | en | https://pornhwa.me | — | likely; manhwa; adult |
| Pornhwa18 | en | https://pornhwa18.com | — | likely; manhwa; adult |
| PornhwaDB Reader | en | https://pornhwareader.com | — | likely; manhwa; adult |
| Pornhwaz | en | https://www.pornhwaz.com | madara | likely; manhwa; adult |
| Qi Scans | en | https://qimanga.com | ezmanhwa | likely; manhwa; ezmanhwa theme |
| Rackus | en | https://rackusreads.com | mangathemesia | likely; manhwa |
| Rage Scans | en | https://ragescans.com | mangathemesia | likely; manhwa; scanlator; focus not verified in code |
| Raven Scans | en | https://ravenscans.org | mangathemesia | confirmed; manhwa |
| Read Solo Leveling Manga Manhwa Online | en | https://ww4.readsololeveling.org | mangacatalog | likely; manhwa; single title (Solo Leveling) |
| Read The Beginning After The End Manga Online | en | https://w22.thebeginningaftertheendmanga.com | solomanga | likely; manhwa; single title (The Beginning After The End) |
| Revival Scans | en | https://www.revivalscans.com | — | likely; manhwa; adult; premium chapters exist |
| RitharScans | en | https://ritharscans.com | keyoapp | likely; manhwa; scanlator; focus not verified in code; paid chapters exist (filtered by the extension) |
| Rizz Comic | en | https://rizzfables.com | mangathemesia | likely; mixed |
| Rolia Scan | en | https://roliascan.com | mangataro | likely; mixed |
| S2Manga | en | https://s2read.com | madara | likely; mixed |
| Sana Scans | en | https://sanascans.com | iken | likely; manhwa; scanlator; focus not verified in code |
| ScansGG | en | https://scans.gg | — | likely; mixed |
| Scythe Scans | en | https://scythescans.com | mangathemesia | likely; manhwa; scanlator; focus not verified in code |
| Siren Scans | en | https://sirenscans.com | keyoapp | likely; manhwa |
| StoneScape | en | https://stonescape.xyz | — | likely; manhwa |
| Temple Scan | en | https://templetoons.com | — | confirmed; mixed; adult; some chapters are premium/locked |
| The Blank | en | https://theblank.net | pam | likely; manhwa; adult |
| The Girl from Random Chatting Manga Online | en | https://thegirlfromrandomchatting.com | — | likely; manhwa; single title (The Girl from Random Chatting) |
| Thunder Scans | en | https://en-thunderscans.com | mangathemesia | likely; manhwa |
| TimelessToons | en | https://timelesstoons.org | keyoapp | likely; manhwa |
| TodayManga | en | https://todaymanga.com | — | likely; mixed |
| Toon Hub | en | https://toon-hub.com | — | likely; mixed; some episodes need VIP membership or coins |
| ToonGod | en | https://www.toongod.org | madara | likely; mixed |
| ToonGrab | en | https://toongrab.com | — | likely; manhwa |
| Toonizy | en | https://toonizy.com | madara | likely; manhwa |
| Toonkotsu | en | https://toonkotsu.com | — | likely; mixed; chapters need unlocking on the site |
| Toonz/Toonx | en | https://toonz.to | — | likely; manhwa |
| Top Manhua | en | https://mangatop.org | madara | likely; manhua |
| TopManhua.fan | en | https://www.topmanhua.fan | madara | likely; mixed; name says manhua but site is manhwa-focused — verify |
| TopManhua.net | en | https://topmanhua.net | madara | likely; manhua; name says manhua but site is manhwa-focused — verify |
| Tower Of God Manga Online | en | https://w85.thetowerofgod.com | mangacatalog | likely; manhwa; single title (Tower of God) |
| Valir Scans | en | https://valirscans.org | vinetheme | likely; manhwa; scanlator; focus not verified in code |
| Violet Scans | en | https://violetmanga.com | mangathemesia | likely; manhwa |
| Vortex Scans | en | https://vortexscans.org | iken | likely; manhwa |
| Webdex Scans | en | https://webdexscans.com | — | likely; mixed; premium chapters exist |
| Webnex | en | https://webnex.cc | — | likely; mixed |
| WebtoonClub | en | https://webtoonclub.com | — | likely; mixed |
| WebtoonLib | en | https://webtoonlib.to | — | likely; mixed |
| WebtoonScan | en | https://webtoonscan.org | madara | likely; mixed; adult |
| WebtoonXYZ | en | https://www.webtoon.xyz | madara | likely; mixed |
| Weeb Central | en | https://weebcentral.com | — | likely; mixed; well-known mixed aggregator |
| Wildtoon | en | https://wildtoon.com | mangathemesia | likely; manhwa; adult |
| Writer Scans | en | https://writerscans.com | keyoapp | likely; manhwa; scanlator; focus not verified in code |
| Zinmanga | en | https://mangazin.org | madara | confirmed; mixed; same group as Zinmanga.net |
| Zinmanga.net | en | https://www.zinmanga.net | madara | confirmed; mixed; same group as Zinmanga |
| Comico.moe | all | https://comico.moe | — | likely; mixed |
| Kagane | all | https://kagane.moe | — | likely; mixed |
| Kodoku Studio | all | https://kodokueasyaccess.com | — | likely; manhwa |
| Lemoon | all | https://lemoon.io | — | likely; mixed; webtoon reader |
| Manga Ball | all | https://mangaball.com | — | likely; mixed |
| Manga Draft | all | https://www.mangadraft.com | — | likely; mixed |
| Manga18fx | all | https://manga18fx.com | yiimadara | likely; manhwa; adult |
| Manga18Me | all | https://manga18.me | yiimadara | likely; mixed; adult |
| MangaDex | all | https://mangadex.org | — | confirmed; mixed; well-known mixed aggregator |
| MangaDNA | all | https://mangadna.com | yiimadara | likely; manhwa |
| MangaDot | all | https://mangadot.net | — | likely; mixed; login needed only for bookmarks |
| MangaFire | all | https://mangafire.to | — | confirmed; mixed; well-known mixed aggregator |
| MangaMachina | all | https://mangamachina.com | — | likely; mixed |
| Manhuarm | all | https://manhuarmtl.com | manhuarm | likely; manhua |
| Manhwa-raw | all | https://manhwa-raw.com | madara | likely; manhwa; adult |
| Manhwa18.cc | all | https://manhwa18.cc | yiimadara | likely; manhwa; adult |
| ManhwaClub.net | all | https://manhwaclub.net | madara | likely; manhwa; adult |
| NamiComi | all | https://namicomi.com | — | likely; manhua; some chapters need a premium subscription |
| OniSaga | all | https://onisaga.com | — | likely; mixed |
| Plumics | all | https://plumics.com | — | likely; mixed |
| Project Suki | all | https://projectsuki.com | — | likely; mixed; tracker/database; some entries link to purchases |
| SayManhwa | all | https://saymanhwa.net | — | likely; manhwa; same group as New Manhwa |
| Susunova | all | https://susunova.xyz | — | likely; mixed; adult |
| ToonLook | all | https://toonlook.com | — | likely; manhwa; adult |
| ToonSpace | all | https://toon.space | — | likely; manhwa; adult |
| XCOMIC | all | https://xcomic.me | — | likely; mixed; also: https://xcomic.net, https://comik.to, https://yona.to |
| Arab Toons | ar | https://arabtoons.net | — | likely; manhwa; adult |
| ArabManhwa | ar | https://arabmanhwa.com | — | likely; manhwa; adult |
| AriaToon | ar | https://ariatoon.com | — | likely; manhwa |
| Empire Webtoon | ar | https://webtoonempire-bl.com | madara | likely; manhwa; adult; BL |
| Kawii Manga | ar | https://kawaiimanga.org | — | likely; mixed |
| MangaAR | ar | https://mangaar.com | — | likely; mixed |
| MangaDar | ar | https://mangadar.com | — | likely; mixed |
| Murim | ar | https://murim-manga.blogspot.com | zeistmanga | likely; manhwa; murim manhwa |
| Paradise BL | ar | https://paradise-bl.com | madara | likely; manhwa; adult; BL |
| Peach BL | ar | https://peach-bl.com | — | likely; manhwa; BL |
| ProComic | ar | https://procomic.pro | — | likely; mixed |
| Rocks Manga | ar | https://rocksmanga.com | — | likely; mixed |
| AKAYA | es | https://akaya.io | — | likely; manhwa |
| Aquellos Que Traducen Scan | es | https://aquellosquetraducen.com | — | likely; mixed |
| CapibaraTraductor | es | https://capibaratraductor.com | — | likely; mixed |
| Catoons | es | https://cattoons.org | — | likely; manhwa; adult |
| Code Arc Mangas | es | https://mangas.codearctraducciones.com | — | likely; mixed; adult |
| Colorcito Scan | es | https://coloresito.site | — | likely; manhwa; adult |
| Emperor Scan | es | https://imperiomanhwa.com | imperiomanhwa | confirmed; manhwa |
| HeavenManga | es | https://heavenmanga.com | — | likely; mixed |
| Ichijou Translations | es | https://ichijoutranslations.com | — | likely; mixed |
| Kumanga | es | https://www.kumanga.com | — | likely; mixed |
| LectorJPG | es | https://visorjpg.lat | — | likely; manhwa |
| LectorXD | es | https://lectorxd.com | — | likely; mixed |
| LeerCapitulo | es | https://www.leercapitulo.co | — | likely; mixed |
| LeerMangaEsp | es | https://mangalect.org | — | likely; mixed |
| LeerManhwas | es | https://leermanhwas.com | yiimanga | likely; manhwa; adult |
| Lmtos | es | https://lmtos.net | — | likely; manhua |
| Manga Crab | es | https://es.mangacrab.org | — | likely; mixed |
| MangaOni | es | https://manga-oni.com | — | likely; mixed |
| MangaVault | es | https://mangavault.lat | — | likely; mixed |
| Manhwa-Latino | es | https://manhwa-latino.com | madara | likely; manhwa; also: https://manhwa-es.com |
| ManhwaOnline | es | https://manhwa-online.com | — | likely; manhwa; adult |
| ManhwaWeb | es | https://manhwaweb.com | — | likely; manhwa |
| Mantraz Scan | es | https://mantrazscanss.lat | — | likely; mixed |
| MHScans | es | https://mhscans.com | — | likely; mixed |
| Mundo Manhwa | es | https://mundomanhwa.com | madara | likely; manhwa; adult |
| NexusScanlation | es | https://nexusscanlation.com | — | likely; mixed; adult; premium chapters exist |
| ONF MANGAS | es | https://onfmangas.com | — | likely; manhwa |
| OrckuMangas | es | https://orckumangas.com | — | likely; mixed; adult |
| Platinum Lily Scan | es | https://platinumlilyscan.com | — | likely; mixed |
| Ragna Scans | es | https://lector.ragnascan.xyz | — | likely; mixed |
| RichtoScan | es | https://r1.richtoon.top | madara | likely; manhwa; premium chapters exist (excluded by the extension) |
| Ritto | es | https://ritto.cc | — | likely; mixed |
| Rncalation | es | https://rncalation.online | — | likely; mixed |
| SkyMangas | es | https://skymangas.com | — | likely; manhua |
| Spicy Scan | es | https://spicyseries.com | — | likely; mixed |
| SpnManga | es | https://www.spnmanga.com | — | likely; mixed |
| Submanhwa | es | https://submanhwa.com | — | likely; manhwa; adult |
| Taurus Fansub | es | https://lectortaurus.com | madara | likely; manhwa; paid chapters exist (filterable) |
| TmoManhwa | es | https://tmomanhwa.com | yiimanga | likely; manhwa; adult |
| Toon-es | es | https://toon-es.com | madara | likely; manhwa; adult |
| Traducciones Moonlight | es | https://traduccionesmoonlight.com | moonlighttl | likely; manhwa |
| Ver Manhwas | es | https://vermanhwa.com | madara | likely; manhwa; adult |
| Aniverse | fr | https://aniverse.fr | — | likely; mixed; premium chapters exist |
| Astral-Manga | fr | https://astral-manga.fr | — | likely; mixed |
| Banchan Scan | fr | https://banchanscan.fr | — | likely; manhwa |
| Epsilon Scan | fr | https://epsilonscan.to | pam | likely; manhwa; adult |
| Japscan | fr | https://www.japscan.foo | — | likely; mixed |
| MangaHub.fr | fr | https://mangahub.fr | madara | likely; mixed |
| Mangas-Origines.fr | fr | https://mangas-origines.fr | origines | likely; mixed |
| Rimu Scans | fr | https://rimuscan.fr | — | likely; mixed; premium chapters exist |
| Soft Epsilon Scan | fr | https://epsilonsoft.to | pam | likely; mixed |
| Solaris Scans | fr | https://solaris-scans.fr | — | likely; mixed; premium chapters exist (hidden by default) |
| Toon FR | fr | https://toonfr.com | madara | likely; manhwa; adult |
| BacaKomik | id | https://bacakomik.my | — | likely; mixed |
| Bacami | id | https://v1.bacami.site | — | likely; mixed |
| CGBUM | id | https://cgbum.com | — | likely; mixed |
| Comicaso | id | https://comicaso.site | — | likely; mixed |
| CosmicScans | id | https://04.cosmicscans.to | — | likely; manhwa |
| Hatsu | id | https://new.hatsu.to | — | likely; mixed |
| Holotoon | id | https://holodek.run | — | likely; manhwa |
| Ikiru | id | https://11.ikiru.wtf | — | likely; mixed |
| IniOtaku | id | https://www.iniotaku2.com | zeistmanga | likely; manhwa |
| IsekaiKomik | id | https://ch1.isekaikomik.site | mangathemesia | likely; manhwa |
| Kiryuu | id | https://v7.kiryuu.to | natsuid | confirmed; mixed; well-known mixed aggregator |
| KomikIndoID | id | https://komikindo.ch | — | likely; mixed |
| KomikRealm | id | https://komikrealm.org | — | likely; mixed |
| Komiku | id | https://komiku.org | — | likely; mixed |
| Mangapoi | id | https://mangapoi.io | zmanga | likely; mixed; adult |
| Manhwa Indo | id | https://www.manhwaindo.my | — | likely; manhwa |
| Manhwa List | id | https://m1.manhwalist.my.id | mangathemesia | likely; manhwa |
| ManhwaDesu | id | https://manhwadesu.rest | mangathemesia | likely; manhwa; adult |
| ManhwaLand.mom | id | https://02.manhwaland.land | mangathemesia | likely; manhwa; adult |
| MG Komik | id | https://web1.mgkomik.cc | — | likely; mixed |
| MyNimeku | id | https://www.mynimeku.com | — | likely; mixed |
| Narasi Ninja | id | https://narasininja.net | — | likely; mixed |
| NgamenKomik | id | https://ngamenkomik05.blogspot.com | zeistmanga | likely; mixed |
| ReYume | id | https://www.re-yume.my.id | zeistmanga | likely; manhua |
| Ryukomik | id | https://ryukomik.com | — | likely; mixed; adult |
| Shinigami | id | https://s1.shinigami.mx | — | likely; mixed |
| Siikomik | id | https://siikomik.com | — | likely; mixed; coin chapters need login + unlock |
| Softkomik | id | https://softkomik.app | — | likely; mixed; ecchi/mature titles require login; also: https://softkomik.online |
| Soul Scans | id | https://v1.soulscans.asia | loneseal | likely; manhwa |
| Tooncubus | id | https://www.tooncubus.top | zeistmanga | likely; manhwa; adult |
| VoraToon | id | https://voratoon.co | — | likely; manhwa |
| West Manga | id | https://v1.westmanga.my | — | likely; mixed; also: https://v1.westmanga.top |
| Wurmz | id | https://wurmz.net | — | likely; mixed |
| Phoenix Scans | it | https://www.phoenixscans.com | pizzareader | likely; manhwa |
| 11toon | ko | https://www.11toon.com | — | confirmed; manhwa |
| BlackToon | ko | https://blacktoon426.com | — | confirmed; manhwa; KR: 블랙툰 |
| Cookmana | ko | https://cookmana12.com | — | confirmed; manhwa; adult |
| DDToon | ko | https://ddtoon33.com | — | confirmed; manhwa; KR: 뚜툰 |
| GoodToon | ko | https://www.goodtoon010.com | madara | confirmed; manhwa |
| Jjaptoon | ko | https://www.jjaptoon008.com | — | confirmed; manhwa; KR: 짭툰 |
| Manga24 | ko | https://ko.manga24.net | madara | confirmed; manhwa; adult; KR: 망가24 |
| NameeToon | ko | https://namee134.asia | — | confirmed; manhwa; KR: 나미툰 |
| Newtoki (SBXH) | ko | https://sbxh9.com | — | confirmed; manhwa; Newtoki mirror |
| NewXToon | ko | https://newxtoon5.com | — | confirmed; manhwa |
| RawDEX | ko | https://rawdex.net | — | confirmed; manhwa; adult |
| Toonkor | ko | https://toonkor0.org | — | confirmed; manhwa |
| Wolf.com | ko | https://wfwf541.com | — | confirmed; manhwa; KR: 늑대닷컴 - 웹툰 |
| Animy | pt-BR | https://animy.net | — | likely; mixed |
| Astratoons | pt-BR | https://new.astratoons.com | — | likely; manhwa |
| Blitz Toons | pt-BR | https://blitztoons.com | — | likely; manhwa |
| BRScans | pt-BR | https://brscans.vercel.app | — | likely; manhwa |
| Capitoons | pt-BR | https://capitoons.com | mangawork | likely; manhwa |
| Cerise Scan | pt-BR | https://loverstoon.net | — | likely; manhwa |
| Ego Toons | pt-BR | https://egotoons.com | — | likely; manhwa |
| Fenix Project | pt-BR | https://fenixproject.website | — | likely; manhwa; adult |
| Funktoon | pt-BR | https://funktoon.com | — | likely; manhwa |
| Geass Comics | pt-BR | https://geasscomics.xyz | — | likely; mixed |
| Hanami Heaven | pt-BR | https://hanamiheaven.org | madara | likely; manhwa; adult |
| Hipertoon | pt-BR | https://hipertoon.com | hiper | likely; manhwa |
| KivaraToons | pt-BR | https://kivaratoons.com | — | likely; manhwa |
| Leitura Manga | pt-BR | https://leituramanga.net | — | likely; mixed |
| Lycan Toons | pt-BR | https://lycantoons.com | — | likely; manhwa |
| Maid Scan | pt-BR | https://empreguetes.wtf | greenshit | likely; manhwa |
| Manga Stop | pt-BR | https://mangastop.net | — | likely; mixed |
| Mango Toons | pt-BR | https://mangotoons.com | — | likely; manhwa |
| Manhastro | pt-BR | https://manhastro.net | — | likely; manhwa; adult |
| Nexus Mangás | pt-BR | https://www.nexusmangas.com | — | likely; mixed |
| Nexus Toons | pt-BR | https://nexustoons.com | — | likely; manhwa |
| NoxManga | pt-BR | https://noxmangas.org | — | likely; mixed |
| OneReader | pt-BR | https://onereader.net | — | likely; mixed; early-access/premium chapters exist |
| Panda Suki | pt-BR | https://pandasuki.net | — | likely; manhwa |
| PizzariaScan | pt-BR | https://pizzariacomics.com | mangawork | likely; mixed |
| Point Zero Toons | pt-BR | https://kitsuneyako.com | — | likely; manhwa |
| Risentoons | pt-BR | https://risentoons.xyz | — | likely; manhwa |
| Sagrado Imp\u00e9rio da Britannia | pt-BR | https://imperiodabritannia.net | mangotheme | likely; mixed |
| TaimuMangas | pt-BR | https://beta.taimumangas.com | — | likely; mixed |
| Tao Sect | pt-BR | https://taosect.com | — | likely; manhua |
| Tia Manhwa | pt-BR | https://tiamanhwa.com | madara | likely; manhwa; adult |
| ToonLivre | pt-BR | https://toonlivre.net | — | likely; manhwa |
| Vegitoons | pt-BR | https://vegitoons.black | greenshit | likely; manhwa |
| Yomu Comics | pt-BR | https://yomu.com.br | — | likely; mixed |
| Yomu Mangás | pt-BR | https://yomumangas.com | — | likely; mixed |
| AstraManga | ru | https://astramanga.org | — | likely; mixed |
| Com-X | ru | https://ru.com-x.life | — | likely; mixed; also: https://com-x.life |
| Desu | ru | https://desu.uno | — | likely; mixed |
| Kyokotsu | ru | https://kyokotsu.com | — | likely; mixed |
| Manga-shi | ru | https://manga-shi.org | — | likely; mixed |
| Mangahub | ru | https://mangahub.ru | — | likely; mixed |
| MangaLib | ru | https://mangalib.me | libgroup | confirmed; mixed; well-known mixed aggregator |
| Tomilo-lib | ru | https://tomilo-lib.ru | — | likely; mixed |
| WaManga | ru | https://wamanga.ru | — | likely; mixed |
| YagamiProject | ru | https://read.yagami.me | — | likely; manhua |
| Bully Manga | th | https://bully-manga.com | — | likely; mixed |
| Manga-TH.Net | th | https://manga-th.net | — | likely; mixed; adult |
| Manga168 | th | https://manga168x.com | — | likely; mixed |
| ManhuaBug | th | https://www.manhuabug.com | madara | likely; manhua |
| ManhuaThai | th | https://www.manhuathai.com | madara | likely; manhua |
| ManhwaBreakup | th | https://www.manhwabreakup.com | madara | likely; manhwa |
| OreManga | th | https://www.oremanga.net | zmanga | likely; mixed |
| Ranker-Manga | th | https://www.ranker-manga.com | zmanga | likely; mixed |
| ReadToon | th | https://readtoon.com | — | likely; manhwa |
| ReaperTrans | th | https://reapertrans.com | mangathemesia | likely; manhwa |
| ToonHunter | th | https://toonhunter.com | mangathemesia | likely; manhwa; adult |
| Toonjai | th | https://toonjai.com | — | likely; manhwa |
| WhyToon | th | https://whytoon.com | — | likely; manhwa; some chapters are premium |
| AeroToon | tr | https://aerotoon.vercel.app | — | likely; manhwa |
| AfterToon | tr | https://aftertoon.com | — | likely; manhwa |
| Gaiatoon | tr | https://gaiatoon.com | mangathemesia | likely; manhwa |
| Gölge Bahçesi | tr | https://golgebahcesi.com | — | likely; mixed |
| Hattori Scans | tr | https://hattoriscans.com | — | likely; mixed |
| Kairatoon | tr | https://kairatoon.net | — | likely; manhwa; some content is premium |
| Koreli Manga | tr | https://korelimanga.com | initmanga | likely; manhwa; Korean (Koreli) manga |
| Koreli Scans | tr | https://www.nabicix.com | mangathemesia | likely; manhwa; Korean (Koreli) scans |
| Luna Scans | tr | https://tuhafscans.com | madara | likely; manhwa; adult |
| Manga Bahçesi | tr | https://mangabahcesi.com | — | likely; mixed |
| Manga Diyarı | tr | https://mangadiyari.com | — | likely; mixed |
| Manga Multi | tr | https://www.mangamulti.com | mangaverse | likely; mixed |
| Manga Portalı | tr | https://www.mangaportali.com | — | likely; mixed |
| Manga Tepesi | tr | https://mangatepesi.com | — | likely; mixed |
| Manga-TR | tr | https://manga-tr.com | — | likely; mixed |
| MangaWT | tr | https://mangawt.com | — | likely; mixed |
| Merlin Scans | tr | https://merlintoon.net | initmanga | likely; manhwa |
| Mikrokosmos Fansub | tr | https://mikrokosmos.fans | — | likely; mixed; adult |
| OkuToon | tr | https://okutoon.net | — | likely; manhwa |
| Opiatoon | tr | https://opiatoon.shop | madara | likely; manhwa; adult |
| OtakuAngel | tr | https://otakuangel.com | — | likely; mixed |
| Piescans | tr | https://piescans.com | — | likely; mixed |
| Seitoon | tr | https://seitoon.com.tr | — | likely; manhwa |
| Slept Manga | tr | https://sleptmanga.com.tr | — | likely; mixed |
| SummerToon | tr | https://summertoons.org | mangathemesia | likely; manhwa; some chapters paywalled |
| TonizuToon | tr | https://tonizu.top | madara | likely; manhwa |
| Toontaku | tr | https://toontaku.com | — | likely; manhwa |
| Webtoon Hatti | tr | https://webtoonhatti.club | madara | likely; manhwa |
| WebtoonOku | tr | https://webtoonoku.org | — | likely; manhwa |
| Zenith Scans | tr | https://zenithscans.com | mangathemesia | likely; manhwa |
| DGManga | uk | https://dgmanga.app | — | likely; mixed |
| Faust | uk | https://faust-web.com | — | likely; mixed |
| MangaInUa | uk | https://manga.in.ua | — | likely; mixed |
| Zenko | uk | https://zenko.online | — | likely; manhua |
| CuuTruyen | vi | https://cuutruyen.net | — | confirmed; mixed; well-known mixed aggregator; also: https://hetcuutruyen.net |
| Dilib | vi | https://dilib.vn | — | likely; mixed |
| Goc Truyen Tranh | vi | https://goctruyentranh.com | — | likely; mixed |
| KhoManhwa | vi | https://khomanhwa.com | — | likely; manhwa; adult |
| L'orange Team | vi | https://lorangeteam.art | — | likely; manhwa; adult |
| LoppyToon | vi | https://loppytoonn.com | — | likely; manhwa |
| MeTruyen18 | vi | https://metruyen18.sbs | — | likely; mixed; adult |
| Top Truyen | vi | https://www.toptruyenzone15.com | wpcomics | likely; mixed |
| TruyenQQ | vi | https://truyenqqko.com | — | likely; mixed |
| TuLinhTruyen | vi | https://api.tulinh.workers.dev | — | likely; mixed |
| Ung Ty Comics | vi | https://ungtycomics.net | — | likely; mixed |
| VietManhwa | vi | https://vietmanhwa.org | — | likely; manhwa; adult |
| ZetTruyen | vi | https://www.zettruyen6.com | — | likely; mixed |
| 177Manhua | zh | https://www.177pica.com | — | likely; manhua; adult |
| 18Manhua | zh | https://18mh.org | goda | likely; manhua; adult |
| 6Manhua | zh | https://www.liumanhua.com | mccms | likely; manhua; adult |
| 90Manhua | zh | https://www.90mh.cc | sinmh | likely; manhua |
| 92Manhua | zh | http://www.92mh.com | sinmh | likely; manhua |
| ACGN | zh | https://comic.acgn.cc | — | likely; mixed |
| Baozi Manhua | zh | https://cn.baozimh.com | — | confirmed; manhua; well-known manhua site; also: https://tw.baozimh.com, https://www.baozimh.com, https://cn.webmota.com, https://tw.webmota.com, https://www.webmota.com, https://cn.kukuc.co, https://tw.kukuc.co, https://www.kukuc.co, https://cn.twmanga.com, https://tw.twmanga.com, https://www.twmanga.com, https://cn.dinnerku.com, https://tw.dinnerku.com, https://www.dinnerku.com, https://appcn.baozimh.com, https://appgb.baozimh.com |
| BH3 | zh | https://comic.bh3.com | — | likely; manhua; official Honkai Impact comics (free) |
| BiliManga | zh | https://www.bilimanga.net | — | likely; manhua |
| BoyLove | zh | https://boylove.cc | — | likely; manhua; adult; also: https://boylove4.xyz |
| Buding Manhua | zh | https://hxy4.com | — | likely; manhua; also: https://qimeitj.com, https://hxy10.com |
| Cartoon18 | zh | https://www.cartoon18.com | — | likely; manhua; adult |
| CManhua | zh | https://cmanhua.com | — | likely; manhua |
| Comicabc | zh | https://www.8comic.com | — | likely; mixed |
| Copy Manga | zh | https://www.mangacopy.com | — | confirmed; mixed; Copy Manga; well-known mixed aggregator; also: https://www.copy4000.com, https://www.copy5000.com, https://www.copy20.com, https://www.copymanga.fun |
| Dm5 | zh | https://www.dm5.com | dm5 | confirmed; mixed; well-known mixed aggregator; also: https://www.dm5.cn |
| Doge Manga | zh | https://dogemanga.com | — | likely; mixed |
| Dumanwu | zh | https://m.dumanwu1.com | mmlook | likely; manhua |
| GoDa | zh | https://baozimh.org | goda | likely; manhua; also: https://godamh.com, https://m.baozimh.one, https://bzmh.org, https://g-mh.org, https://m.g-mh.org |
| Guazimanhua | zh | https://www.guazimanhua.com | — | likely; manhua |
| H-Comic | zh | https://h-comic.com | — | likely; manhua; adult |
| HANMAN18 | zh | https://hanman18.com | manga18 | likely; manhwa; adult; Korean adult manhwa (Chinese translation) |
| Hikarinagi | zh | https://www.hikarinagi.org | — | likely; mixed |
| Ikanmh | zh | https://ikanmh.xyz | — | likely; manhua; adult; also: https://ikanmh.cc, https://www.jjmhw12.top |
| Ikmmh | zh | https://ymcdnyfqdapp.ikmmh.com | — | likely; manhua; adult |
| JComic | zh | https://jcomic.net | — | likely; manhua; adult |
| Jisu Manhua | zh | https://www.1kkk.com | dm5 | likely; manhua |
| Kanman | zh | https://www.kanman.com | — | likely; manhua |
| Komiic | zh | https://komiic.com | — | confirmed; mixed; well-known mixed aggregator; also: https://komiic.cc |
| Longjinman | zh | https://long52.com | — | likely; manhua; adult; also: https://long92.org, https://long91.xyz, https://long91.org, https://long91.com, https://long7.org, https://long5.org, https://longjm.org, https://52longjm.com, https://long9.xyz, https://long6.xyz, https://long82.com, https://long81.com, https://long7.xyz, https://longjm.com |
| Manben | zh | https://www.manben.com | — | likely; manhua |
| Manga Xiao Si | zh | https://www.jjmhw2.top | — | likely; manhua; adult; also: https://www.jjmh.top, https://mxs13.cc |
| Mangabz | zh | https://mangabz.com | — | likely; mixed; also: https://xmanhua.com, https://yymanhua.com |
| Manhua Manga | zh | https://www.mh03.com | mccms | likely; manhua |
| Manhua160 | zh | https://www.mh160mh.com | — | likely; manhua |
| ManHuaGui | zh | https://www.manhuagui.com | — | confirmed; mixed; well-known mixed aggregator; also: https://tw.manhuagui.com, https://www.mhgui.com, https://tw.mhgui.com |
| Manhuaren | zh | https://mangaapi.manhuaren.com | — | likely; manhua; API host |
| Manhuashe | zh | https://www.311s.com | — | likely; manhua; also: https://www.m206.com |
| Manhuawu | zh | https://www.mhua5.com | mccms | likely; manhua |
| Manwa | zh | https://manwa.me | — | likely; mixed |
| Manwa Manhua | zh | https://manwadz.cc | — | likely; manhua |
| Manwame | zh | https://manwame.com | — | likely; manhua |
| MH1234 | zh | https://m.wmh1234.com | — | likely; manhua |
| Miaoqu Manhua | zh | https://www.miaoqumh.org | mccms | likely; manhua |
| Mikagogo | zh | https://mikagogo.com | — | likely; mixed |
| MyComic | zh | https://mycomic.com | — | likely; mixed |
| NNHanman | zh | https://nnhanman.xyz | — | likely; manhwa; adult; Korean adult manhwa (Chinese translation) |
| Relamanhua | zh | https://m.2024manga.com | — | likely; manhua |
| Roumanwu | zh | https://rouman5.com | — | likely; manhua; adult |
| Rumanhua | zh | https://m.rumanhua2.com | mmlook | likely; manhua |
| Terra Historicus | zh | https://comic.hypergryph.com | — | likely; manhua; official Arknights comics (free) |
| TIBIU Comics | zh | https://comic.tibiu.net | mccms | likely; manhua; adult |
| Utifa | zh | https://bx.yaimer.com | — | likely; mixed |
| vomic | zh | https://vomicmh.com | — | likely; manhua |
| Woaimanhua | zh | https://www.woimh.com | — | likely; manhua |
| Xipi Manhua | zh | https://m.hipmh.com | — | likely; manhua |
| Xsnvshen | zh | https://m.xsnvshen.com | — | likely; manhua; adult |
| YKMH | zh | https://www.ykmh.net | sinmh | likely; manhua |
| Zaimanhua | zh | https://manhua.zaimanhua.com | — | likely; manhua; optional account login |
| Zazhimi | zh | https://www.zazhimi.net | — | likely; manhua |
| Zerobyw | zh | http://www.zerobyw33.com | — | likely; mixed |
| Dongman Manhua | zh-Hans | https://www.dongmanmanhua.cn | — | likely; manhua |
| YellowNote | zh-Hans | https://xchina.co | — | likely; manhua; adult |
| Creative Comic Collection | zh-Hant | https://www.creative-comic.tw | — | likely; manhua; Taiwanese original comics platform (free) |

## Login required (22)

| Name | Lang | Site | Theme | Notes |
| --- | --- | --- | --- | --- |
| Dashtoon | en | https://dashtoon.com | — | likely; manhwa; official platform; access unknown — verify |
| Toonily | en | https://toonily.com | madara | likely; manhwa; login required to read; freemium, some chapters may need subscription — verify |
| ToonTop | en | https://toontop.io | mangak | likely; manhwa; adult; Toonily mirror/alternate; login required to read |
| Lunar Manga | all | https://lunarx.to | — | likely; manhwa; login needed; chapters unlock with free ad points |
| Taddy INK (Webtoons) | all | https://taddy.org | — | likely; manhwa; access unknown — verify |
| Catharsis World | es | https://newcatharsis.dig-it.info | catharsis | likely; manhwa; some chapters require login on the site |
| ManhwaShot | es | https://manhwashot.lat | — | likely; manhwa; some chapters require login |
| MirrorKomik | id | https://mirrorkomik.info | — | likely; mixed; some chapters redirect to login |
| Azuretoons | pt-BR | https://azuretoons.com | — | likely; manhwa; requires login (auto-login with stored credentials) |
| Bladetoons | pt-BR | https://bladetoons.com | mangotheme | likely; manhwa; requires login (code: requiresLogin) |
| Hunters Scans | pt-BR | https://readhunters.xyz | madara | likely; manhwa; requires login via WebView |
| Mediocre Toons | pt-BR | https://laranjatoons.com | — | likely; manhwa; requires login (email/password in extension prefs) |
| Roxinha | pt-BR | https://roxinha.online | — | likely; mixed; requires login (email/password; 401 without) |
| ToonBr | pt-BR | https://beta.toonbr.com | — | likely; manhwa; requires login (email/password in extension prefs) |
| Holy Scans | tr | https://holyscans.com.tr | — | likely; mixed; adult; requires login (WordPress account); some chapters VIP/coin |
| Mangadusleri | tr | https://mangadusleri.mom | — | likely; manhwa; adult; some content redirects to login |
| Webtoonia | tr | https://webtoonia.fun | madara | likely; manhwa; adult; some content requires login |
| Xiaoaitoon | tr | https://xiaoaitoon.org.tr | — | likely; manhwa; adult; some chapters locked unless logged in |
| Baka Manhua | zh | https://bakamh.com | madara | likely; manhua; adult; some chapters locked unless logged in |
| FavComic | zh | https://www.favcomic.com | — | likely; mixed; requires login (stored credentials); also: https://www.favcomic.xyz, https://www.favcomic.net, https://www.favcomic.cc |
| HanabiManga | zh | https://uhkvqrxmcapgtpspglrp.moedot.net | — | likely; mixed; requires login (token-based API) |
| NoyAcg | zh-Hant | https://api.noyteam.online | — | likely; mixed; requires login (API); also: https://api.noymanga.com, https://api.noy.asia |

## Dropped as paywalled (official platforms)

Naver Webtoon (`comic.naver.com`), RidiBooks (`ridibooks.com`), Tappytoon, Tapas, Toomics, Manta, INKR, Coolmic, K Manga, Kodansha, BookWalker, Manga UP!, MangaToon, Honeytoon, Webtoons.com (LINE Webtoon), Comikey, GlobalComix, Webcomics app, Heytoon, Bilibili Manga, Tongli, Toptoon, Kuaikan Manhua, Tencent Comics, iQiyi, BookWalker Taiwan.

## Excluded for other reasons

- `(unoriginal)` clones (Asuratoons, ToonGod.cc, Coffee Manga, KaliScan, YaoiScan.me, Qtoon, Hentai20.ink, LectorTMO clones, ZonaTMO clones, Comick.live, NetTruyen clones, CuuTruyen.moe, etc.)
- Hentai-only / doujin galleries (Hentai18, HentaiFull, HentaiRead, NHentai.to, Sankaku Complex, WNACG, Jinman Tiantang, Picacomic, etc.) — adult manhwa/manhua sites are kept and tagged instead.
- Manga-only sites (Japanese manga aggregators and scanlators with no manhwa/manhua evidence in code, e.g. TCB Scans, Dynasty Scans).
- Novel sites (WebNovel, J-Novel, WuxiaWorld, NovelCool), anime/video sites, imageboards/galleries, local self-hosted servers (Komga, Kavita-style, Mango, LANraragi, Jellyfin).
- Japanese (`ja`, 153 sources) in full — manga by definition.
