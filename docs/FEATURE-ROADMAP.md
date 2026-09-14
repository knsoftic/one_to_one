| [x] || [x] || [x] || [x] || [x] |# One2One Chat — Feature Roadmap (WhatsApp jaisi features)

Ye list un features ki hai jo WhatsApp mein hain (aur kuch mazeed kaam ke) lekin abhi One2One Chat mein **nahi** hain.
Hum inhe **ek ek karke** banayenge. Kaam shuru karne ke liye bas number batayein, jaise: *"M1 banao"*.

**Mehnat:** 🟢 Aasaan (kuch ghante) · 🟡 Darmiyana (1–2 din) · 🟠 Bara (kai din) · 🔴 Bohat bara (hafte)
**Status:** `[ ]` baqi · `[~]` ban raha hai · `[x]` mukammal

---

## ✅ Jo pehle se app mein hai

Register/login, profile photo, password reset · one-to-one chat · text, emoji, photo (JPG/PNG), PDF/DOC/DOCX, voice note ·
reply, edit ("Edited"), copy, delete for me / everyone · ✓ sent, ✓✓ delivered, blue ✓✓ seen · typing… · online dot, last seen ·
user search, recent chats, unread filter aur badges · block/unblock · phone contacts (saved naam ke saath) ·
notification centre, browser notifications · Android app: push notification (Reply / Mark as read), contacts,
internet band/slow bar · **audio aur video call** (full-screen ringing, speaker, camera switch, call history) ·
light/dark theme · admin panel.

---

## Phase 1 — Messages (jaldi banne wale, sabse zyada farq) — ✅ mukammal

> Tafseel aur tests: `development-progress.md` → "Roadmap Phase 1". Android app mein location ke liye naya APK build karna hoga.

| # | Feature | Kya milega | Mehnat | Status |
|---|---------|-----------|--------|--------|
| M1 | **Forward message** | Message ek ya kai chats ko bhejna, "Forwarded" label | 🟢 | [x] |
| M2 | **Emoji reactions** | Message par 👍❤️😂😮😢🙏 lagana, kisne lagaya dikhe | 🟢 | [x] |
| M3 | **Chat ke andar search** | Kisi chat mein purana message dhoondna, us tak scroll | 🟢 | [x] |
| M4 | **Text formatting** | \*bold\*, \_italic\_, \~strike\~, \`\`\`mono\`\`\`, lists | 🟢 | [x] |
| M5 | **Star message** | Zaroori message save karna, "Starred messages" list | 🟢 | [x] |
| M6 | **Pin message** | Chat ke oopar message pin karna (24h / 7 din / 30 din) | 🟢 | [x] |
| M7 | **Message info** | Message kab deliver aur kab read hua | 🟢 | [x] |
| M8 | **"Recording audio…" indicator** | Voice note record karte waqt doosre ko dikhe | 🟢 | [x] |
| M9 | **Voice note speed** | 1x / 1.5x / 2x, sunte hue agla voice note khud chale | 🟢 | [x] |
| M10 | **Draft save** | Adhoora likha message chat badalne par mehfooz rahe, list mein "Draft" | 🟢 | [x] |
| M11 | **Link preview** | Link ke saath title, tasveer aur site ka naam | 🟡 | [x] |
| M12 | **Video bhejna** | MP4 video, thumbnail, player, size limit | 🟡 | [x] |
| M13 | **Kai photos ek saath + caption** | Album ki shakal mein, har photo ka caption | 🟡 | [x] |
| M14 | **Photo edit** | Bhejne se pehle crop, rotate, draw, text | 🟡 | [x] |
| M15 | **App ke andar camera** | Seedha photo/video khench kar bhejna | 🟡 | [x] |
| M16 | **Mazeed files** | ZIP, Excel, PowerPoint, MP3, APK waghera; "HD photo" option | 🟢 | [x] |
| M17 | **GIF aur stickers** | GIF search, sticker packs, apni photo se sticker | 🟠 | [x] |
| M18 | **Location** | Current location aur live location (15 min / 1 ghanta / 8 ghante) | 🟡 | [x] |
| M19 | **Contact card** | Phone contact bhejna, tap par chat/save | 🟢 | [x] |
| M20 | **Poll** | Sawal aur options, vote, results | 🟡 | [x] |
| M21 | **Disappearing messages** | 24 ghante / 7 din / 90 din baad khud delete | 🟡 | [x] |
| M22 | **View once** | Photo/video/voice jo sirf ek dafa khule | 🟡 | [x] |
| M23 | **Clipboard se photo paste** | Ctrl+V / copy kar ke photo bhejna (pehle se tha) | 🟢 | [x] |

## Phase 2 — Chat list — ✅ mukammal

> Tafseel aur tests: `development-progress.md` → "Roadmap Phase 2". Naye migrations chalane honge (chat_settings, chat_lists, users.chat_lock_pin); naya APK nahi chahiye.

| # | Feature | Kya milega | Mehnat | Status |
|---|---------|-----------|--------|--------|
| C1 | **Pin chat** | 3 chats tak list ke oopar | 🟢 | [x] |
| C2 | **Mute chat** | 8 ghante / 1 hafta / hamesha — notification band | 🟢 | [x] |
| C3 | **Archive chat** | Chat "Archived" mein, naya message aane par bhi wahin rahe | 🟢 | [x] |
| C4 | **Mark as unread / read** | List se hi unread nishan lagana/hatana | 🟢 | [x] |
| C5 | **Clear chat / Delete chat** | Sirf apni taraf se saare messages ya poori chat hatana | 🟢 | [x] |
| C6 | **Favorites aur apni lists** | "Favorites", "Family", "Work" jaise filters | 🟡 | [x] |
| C7 | **Message yourself** | Apne aap ko notes/files bhejna | 🟢 | [x] |
| C8 | **Invite friends** | Jo contact app par nahi, usse WhatsApp/SMS se download link bhejna | 🟢 | [x] |
| C9 | **Chat lock** | Kisi chat ko fingerprint/PIN ke peeche chhupana | 🟡 | [x] |

## Phase 3 — Calls — ✅ mukammal

> Tafseel aur tests: `development-progress.md` → "Roadmap Phase 3". Naye migrations chalane honge (call_rooms, call_room_participants, call_links); naya APK nahi chahiye.

| # | Feature | Kya milega | Mehnat | Status |
|---|---------|-----------|--------|--------|
| K1 | **Calls tab** | Saari calls ki list (missed laal mein), wahin se call back | 🟢 | [x] |
| K2 | **Voice call → video call** | Chalti call mein camera on karke video mein badalna | 🟡 | [x] |
| K3 | **Picture-in-picture** | Video call chhoti floating window mein, chat bhi chalti rahe | 🟡 | [x] |
| K4 | **Screen sharing** | Desktop/phone ki screen dikhana | 🟡 | [x] |
| K5 | **Call quality indicator** | Kamzor internet par "Poor connection" aur low-data mode | 🟢 | [x] |
| K6 | **Group calls** | 3–8 log audio/video (media server chahiye) | 🔴 | [x] |
| K7 | **Call links** | Link bhej kar call mein bulana | 🟡 | [x] |

## Phase 4 — Groups — ✅ mukammal

| # | Feature | Kya milega | Mehnat | Status |
|---|---------|-----------|--------|--------|
| G1 | **Group banana** | Naam, photo, description, members add | 🟠 | [x] |
| G2 | **Group admins** | Admin banana/hatana, members nikalna | 🟡 | [x] |
| G3 | **Invite link / QR** | Link se group join, link reset | 🟡 | [x] |
| G4 | **@mentions** | Member ko tag karna, alag notification | 🟡 | [x] |
| G5 | **Group settings** | Sirf admin message bhejein, group info kaun badle | 🟢 | [x] |
| G6 | **Read by / Delivered to** | Group message kis kis ne padha | 🟡 | [x] |
| G7 | **Reply privately** | Group message ka jawab akele mein | 🟢 | [x] |
| G8 | **Leave / delete group** | Group chhodna, admin ka group khatam karna | 🟢 | [x] |
| G9 | **Broadcast list** | Ek message kai logon ko alag alag (unhe pata na chale) | 🟡 | [x] |
| G10 | **Communities** | Kai groups ek chhat ke neeche, announcement group | 🟠 | [x] |
| G11 | **Channels** | Ek-taraf updates, followers, reactions | 🟠 | [x] |

## Phase 5 — Status (Stories) — ✅ mukammal

| # | Feature | Kya milega | Mehnat | Status |
|---|---------|-----------|--------|--------|
| S1 | **Text / photo / video status** | 24 ghante ke liye, rang aur font wala text status | 🟠 | [x] |
| S2 | **Kisne dekha** | Viewers ki list aur waqt | 🟢 | [x] |
| S3 | **Status privacy** | Sab contacts / chune hue / in ke siwa | 🟡 | [x] |
| S4 | **Status ka jawab aur reaction** | Status par reply chat mein pahunche | 🟢 | [x] |
| S5 | **Mute status** | Kisi ka status neeche chhupana | 🟢 | [x] |

## Phase 6 — Privacy aur security

| # | Feature | Kya milega | Mehnat | Status |
|---|---------|-----------|--------|--------|
| P1 | **Last seen / online privacy** | Everyone / My contacts / Nobody | 🟡 | [ ] |
| P2 | **Profile photo aur About privacy** | Kaun dekh sake | 🟢 | [ ] |
| P3 | **Read receipts band** | Blue tick off (dono taraf) | 🟢 | [ ] |
| P4 | **About (profile status text)** | "Busy", "At work" jaisa text | 🟢 | [ ] |
| P5 | **Blocked contacts list** | Settings mein saare blocked log, wahin se unblock | 🟢 | [ ] |
| P6 | **Report user** | Spam/abuse report, admin panel mein review | 🟡 | [ ] |
| P7 | **Two-step verification** | 6 digit PIN, naye device par login ke waqt | 🟡 | [ ] |
| P8 | **App lock** | Android app kholne par fingerprint/PIN | 🟢 | [ ] |
| P9 | **Active sessions** | Kin devices par login hai, dusre devices se logout | 🟡 | [ ] |
| P10 | **Linked devices (QR login)** | Phone se QR scan karke web par login, WhatsApp Web ki tarah | 🟡 | [ ] |
| P11 | **End-to-end encryption** | Server par bhi messages na padhe ja sakein (bohat bari tabdeeli) | 🔴 | [ ] |

## Phase 7 — Account

| # | Feature | Kya milega | Mehnat | Status |
|---|---------|-----------|--------|--------|
| A1 | **Phone number + OTP login** | Password ke baghair SMS code se login (SMS ka kharcha) | 🟡 | [ ] |
| A2 | **Change number** | Naya number, chats saath rahein | 🟡 | [ ] |
| A3 | **Delete my account** | User khud account aur data hataye | 🟢 | [ ] |
| A4 | **Profile QR code** | QR scan karke seedha chat shuru | 🟢 | [ ] |
| A5 | **Mera data download** | Account ki report/export | 🟡 | [ ] |

## Phase 8 — Media, storage aur chat settings

| # | Feature | Kya milega | Mehnat | Status |
|---|---------|-----------|--------|--------|
| D1 | **Media, Links, Docs gallery** | Har chat ki saari photos, links aur files ek jagah | 🟡 | [ ] |
| D2 | **Chat wallpaper** | Rang ya tasveer, har chat ka alag | 🟢 | [ ] |
| D3 | **Font size** | Chhota / darmiyana / bara | 🟢 | [ ] |
| D4 | **Har chat ki notification tone** | Alag awaaz, vibration | 🟢 | [ ] |
| D5 | **Auto-download settings** | Wi-Fi / mobile data par kya khud download ho | 🟡 | [ ] |
| D6 | **Storage manage** | Bari files dekhna aur hatana | 🟡 | [ ] |
| D7 | **Export chat** | Chat text/zip file mein | 🟢 | [ ] |
| D8 | **Chat backup / restore** | Google Drive ya server backup, naye phone par wapas | 🟠 | [ ] |

## Phase 9 — Apps aur platform (mazeed)

| # | Feature | Kya milega | Mehnat | Status |
|---|---------|-----------|--------|--------|
| X1 | **Urdu zabaan** | Poora app Urdu mein (right-to-left), English/Urdu option | 🟠 | [ ] |
| X2 | **Share into app** | Doosri app se photo/file/link "Share → One2One Chat" | 🟡 | [ ] |
| X3 | **Browser push (PWA)** | Computer par tab band ho tab bhi notification; install as app | 🟡 | [ ] |
| X4 | **App update prompt** | Nayi APK aane par "Update available" | 🟢 | [ ] |
| X5 | **Play Store release** | Signed release, store listing, privacy policy page | 🟡 | [ ] |
| X6 | **Desktop keyboard shortcuts** | Ctrl+K search, Esc, Alt+↑/↓ chat badalna | 🟢 | [ ] |
| X7 | **iPhone app** | iOS build (Mac + Apple Developer $99/saal chahiye) | 🟠 | [ ] |
| X8 | **Business tools** (optional) | Business profile, quick replies, away message, labels | 🟠 | [ ] |

---

## Tajweez karda tarteeb

1. **Phase 1 + 2 ke aasaan features:** M1 Forward, M2 Reactions, M3 Chat search, M4 Formatting, C1 Pin, C2 Mute, C3 Archive, K1 Calls tab.
2. **Media:** M12 Video, M13 kai photos, M11 Link preview, D1 Media gallery.
3. **Privacy:** P1, P3, P4, P5.
4. **Groups:** G1 se G8 tak.
5. **Status:** S1 se S5 tak.
6. Baqi apni zaroorat ke hisaab se.

Har feature mukammal hone par yahan `[x]` lagega aur `development-progress.md` mein tafseel likhi jayegi.
