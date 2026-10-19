# TMC CampusLF: Project Specification (Trinidad Municipal College Lost & Found)

Platforms: Web Admin Portal (Laravel PHP + Blade, ADMINS ONLY), Mobile App (Flutter Dart, USERS ONLY), MySQL database (inspected with MySQL Workbench), edited in Visual Studio Code.
Legend: [Mobile] = Flutter app; [Web] = Laravel Blade admin portal; (Threat #n) = risk the item addresses (see section E).

# PART 1: FUNCTIONAL MODULES

## 1.  User Authentication Module

### 1.1.  User Registration
- **[Mobile]** Allows TMC students, staffs, and instructors to create an account by providing valid personal and academic information (full name, TMC ID number, TMC email, department/program, and user type), ensuring only authorized users can access the system.
- Sign-up is accepted only from the official TMC email domain (set once in `.env` as `TMC_EMAIL_DOMAIN`) and the account is activated only after the user enters the one-time code (OTP) sent to that email. (Threat #2, #3)
- The TMC ID number and email are checked against the TMC masterlist that admins import on the web portal. Entries that do not match are placed in "Pending" status for admin approval instead of being activated automatically. (Threat #3)
- A CAPTCHA challenge and request rate limiting protect the registration endpoint from bots. (Threat #2)
- The user must tick the Terms, Privacy Notice and Disclaimer (TMC CampusLF is a facilitator, not a custodian) before the account is created; acceptance and its date/time are saved. (Threat #16)

### 1.2.  User Login
- **[Mobile]** Validates the user's credentials and grants secure access to the system based on their assigned role. Only accounts with the role **user** can sign in on the mobile app; an admin account that tries to sign in is refused with a message to use the web portal.
- **[Web]** Only accounts with the role **admin** can sign in on the web portal; a user account that tries to sign in is refused.
- Passwords are stored hashed (bcrypt/Argon2). Failed logins are throttled (e.g., 5 attempts per minute) with a temporary lockout, and the admin login page also uses a CAPTCHA. Suspended or deactivated accounts cannot sign in. (Threat #2, #5)
- Signing in from a new device requires an OTP sent to the TMC email, which blocks hijacked accounts. (Threat #3)
- The mobile app keeps its API (Sanctum) token in secure storage (`flutter_secure_storage`); the web portal uses a secure, HTTP-only session cookie with CSRF protection. (Threat #5)

### 1.3.  User Logout
- Safely terminates the user session to prevent unauthorized access to the account.
- **[Mobile]** Deletes the API token on the server and clears the secure storage on the phone. (Threat #5)
- **[Web]** Invalidates the session, regenerates the CSRF token, and automatically logs out an idle admin after a set time (e.g., 30 minutes). (Threat #5)

### 1.4.  Role-Based Access
- Limits system features according to roles. Users (mobile app) can post, comment, react, claim, and message, while administrators (web portal) can moderate content and monitor activity.
- Roles cannot be chosen upon registration; every self-registered account is automatically a user. No one can register as admin. Admin accounts are created only by the system seeder or by an existing admin, and an admin can register a user through the admin controls in the web portal.
- Access is enforced on the server with Laravel middleware (`role:user` on the `/api` routes, `role:admin` on the web routes) and policies, so hiding a button is never the only protection. (Threat #3, #5)

### 1.5.  Manage User Accounts
- **[Web]** Admins can create a user account, edit details, reset a password, suspend or reactivate an account, approve "Pending" registrations, and import or update the TMC masterlist (CSV). (Threat #3)

### 1.6.  User Profile & Privacy
- **[Mobile]** Users can view and update their profile and change their password. Other users see only the first name; ID number, email and phone are never shown publicly. (Threat #4)

## 2.  Post Submission Module

### 2.1.  Create Lost & Found Post
- **[Mobile]** Allows users to prompt/input item details (name, description, date, location, and unique questions for the found items when someone clicks the claim button) to create a lost or found post.
- Structured, required fields: category (Phone, Laptop, Accessory, ID/Wallet, Bag, Books/Supplies, Clothing, Other), color, location (picked from the TMC campus areas list managed by admins), and date. These fields make accurate matching possible. (Threat #7, #15)
- For found posts the finder chooses the item's current custody: "With me" or "Surrendered to the TMC office". High-value items (IDs, phones, wallets, laptops) show a recommendation to also report them to the official TMC lost and found/security office. (Threat #8, #16)
- The finder writes 1 to 3 verification questions and their correct answers. Answers are saved encrypted and are never sent to other users by the API; only admins can read them on the web portal. (Threat #1)
- Before submitting, similar existing posts are shown and the user confirms (see 9.2). Daily post limits, a blocked-words filter, and automatic masking of phone numbers/emails in text fields apply (see 10.2). (Threat #2, #4, #6, #12)

### 2.2.  Upload Post Photos
- **[Mobile]** Enables users to attach one or more images of the lost or found item to help with identification (however, the photos must not hint to the unique questions as it risks fake claims of the found items). Moreover, lost posts have two buttons under the question "Have you seen this item?": Yes (button 1) and No (button 2). (Threat #1)
- Pressing **Yes** opens an evidence form (description and photo) that is sent to the post author, then to admins for review (see 4.1 and 6.6). Pressing **No** only records the answer.
- Uploads are validated on the server: JPG/PNG/WEBP only, size limit (e.g., 5 MB), maximum 5 photos per post, random file names, and re-encoding of the image to strip hidden data. (Threat #5, #6)
- An image description (alt text) is required for each photo and is pre-filled from the item name and category. (Threat #17)
- A reminder is shown while uploading not to photograph unique details (serial numbers, lock-screen text, ID details, contents) used in the verification questions; admins check this during review and can hide offending posts. (Threat #1)

### 2.3.  Edit or Delete Own Post
- **[Mobile]** Allows the author to update the details of their own post or remove it entirely.
- While a claim request or a "Yes" evidence submission is pending, the verification questions and key details are locked so answers cannot be changed to fit a claimant. Deleting a post with pending requests asks for confirmation and notifies the affected users. Every edit is recorded in the activity log. (Threat #1)

### 2.4.  Browse & Search Posts
- **[Mobile]** Displays all active lost and found posts and allows users to search or filter them by lost item or found item and also by category (Phone, laptop, accessory, etc.), date, or status (active/retrieved). Color and location filters are also available. (Threat #7)
- Results are paginated (e.g., 15 per page) with infinite scroll in the app, and the searched columns (category, status, date) are indexed in MySQL, so the app stays fast during busy periods such as exams and enrollment. (Threat #13)
- Hidden, archived, and retrieved posts never appear in the Community Feed. All posts belong to TMC, so nothing from outside the college is shown. (Threat #15)

## 3.  Post Interaction Module

### 3.1.  Comment on Post
- **[Mobile]** Allows users to leave comments on a post, such as leads, questions, or additional information and discussion.
- Phone numbers, emails, and social handles in comments are masked automatically, and a notice reminds users never to post answers to verification questions in public comments. Users can delete their own comments, and comments can be reported. (Threat #1, #4)

### 3.2.  React to Post
- **[Mobile]** Lets users react to a post using Like, Haha, Angry, or Sad reactions to express their response. One reaction per user per post; the user can change or remove it.

### 3.3.  Report Post
- **[Mobile]** Allows users to flag a post as inappropriate, spam, or fake, sending it to admins for review. (Threat #6)
- One report per user per post. When a post reaches the report threshold (default 3 different users, adjustable in settings) it is hidden automatically until an admin reviews it. Repeated false reporting is visible to admins. (Threat #2, #6)

## 4.  Post Status Management Module

### 4.1.  Mark Post as Retrieved
- **[Mobile]** Allows the author to mark their own post as "Retrieved" once the lost or found item has returned to its owner, closing the post and removing it from the Community Feed where active posts are found.
- **Lost posts:** after a user clicks Yes with evidence, the author reviews the evidence and approves it, then awaits admin approval before meet-up locations can be discussed.
- **Found posts:** after a claim request, an admin compares the answers (see 6.5) and approves it before the claimant and the finder can message each other. (Threat #1)
- A post becomes "Retrieved" only after the handover is confirmed by both parties in the app or by the handover code/QR check (see 7.3). An admin can also mark a post as retrieved from the web portal, with a note, when the handover happens at the office. (Threat #9)

### 4.2.  Post Status Display
- **[Mobile]** Shows whether a post is still active or has already been marked as retrieved. Statuses: Active, Claim Pending, Approved for Meet-up, Retrieved, Under Review (hidden), and Archived. (Threat #9, #11)
- **[Web]** Admins see the same statuses and can filter every list by status.

## 5.  Content Moderation Module

### 5.1.  Delete Post
- **[Web]** Allows admins to permanently remove a post that violates community guidelines. Deleting also purges the post's images from storage, and the action is recorded in the audit trail (see 6.8) with the reason. (Threat #6)

### 5.2.  Hide Post
- **[Web]** Allows admins to temporarily hide a reported or inappropriate post from public view. A hidden post can be restored if the review finds no violation; the author still sees it marked "Under Review". (Threat #6)

### 5.3.  Notify Author of Action
- Automatically sends the post's author a notification explaining the reason their post was deleted or hidden, and which guideline it broke. It is sent as a push notification with an email backup (see Module 8). (Threat #6, #14)

### 5.4.  Review Reported Posts
- **[Web]** Displays all posts that have been reported by users so admins can review and take action. Each item shows the report reasons, the number of reporters, the full post, and the author's history. Actions: dismiss, hide, delete, warn the author, or suspend the author. (Threat #6)

### 5.5.  Warn or Suspend Users
- **[Web]** Admins can send a warning or suspend a user for a set period (or until review) for repeated spam, fake claims, or abuse. The user is notified with the reason, and the action is saved in the audit trail. (Threat #1, #2, #6)

### 5.6.  Review Reported Comments & Messages
- **[Web]** Reported comments and chat messages appear in the same review queue. Admins can open a conversation only if it has been reported, which protects users' private chats. (Threat #4, #6)

## 6.  User Activity Monitoring Module

### 6.1.  Monitor Login/Logout Activity
- **[Web]** Tracks and displays user login and logout events from both the mobile app and the web portal for security and oversight purposes, including date/time, device, and IP address. Repeated failed attempts and new-device logins are flagged. (Threat #3, #5)

### 6.2.  Monitor User Registrations
- **[Web]** Displays a log of newly registered users on the platform, including verification status (OTP verified, masterlist matched, pending approval). (Threat #3)

### 6.3.  Monitor User Interactions
- **[Web]** Tracks user engagement such as comments and reactions (Like, Haha, Angry, Sad) made across posts.

### 6.4.  Monitor Community Feed
- **[Web]** Tracks all lost and found posts submitted by users, including all information in the post, and can filter by hidden, archived, and retrieved posts.

### 6.5.  Monitor Found Items
- **[Web]** Tracks all found posts submitted by users, including images uploaded, unique claim questions, and other information. Displays the claim requests from users to a post and lets the admin decide if the answers of the post author and the claim requester matched, to approve or not. By approving, users can message each other and decide on safe locations provided by admins in "Meet-up Locations".
- The review screen shows the finder's answers and the claimant's answers side by side. A claimant has a limited number of attempts per item (default 3), after which further claims need admin permission. (Threat #1)
- Approval opens the masked chat and generates the handover code (see 7.2 and 7.3); rejection notifies the claimant without revealing the correct answers. (Threat #1, #4)

### 6.6.  Monitor Lost Items
- **[Web]** Tracks all lost posts submitted by users, including images uploaded and other information. Displays lost posts with Yes answers with evidence to the lost item question, and approves the item return if the post author's description and image match the evidence, so the users can discuss the safe meet-up locations provided by admins. (Threat #1, #8)

### 6.7.  Activity Log Export
- **[Web]** Allows admins to export the monitored activity data (CSV or PDF, with a date-range filter) for record-keeping or reporting. Exports leave out passwords and verification answers, and each export is itself logged. (Threat #5)

### 6.8.  Admin Audit Trail
- **[Web]** Records every admin action (hide, delete, approve, reject, suspend, export, settings change) with the admin, time, target, and reason, so moderation decisions can be reviewed in case of a dispute. (Threat #5, #9)

## 7.  Safe Handover & Messaging Module

### 7.1.  Manage Meet-up Locations
- **[Web]** Admins add, edit, or deactivate the safe meet-up locations (for example the campus security office or the help desk) together with their operating hours. (Threat #8)
- **[Mobile]** After approval, users can only choose from the active list of Meet-up Locations; free-typed meeting places are not allowed. (Threat #8)

### 7.2.  Masked In-App Messaging
- **[Mobile]** Opens a chat between the post author and the approved claimant (or approved finder) only after admin approval. Contact details are never shown; only first names appear, and phone numbers, emails, and social handles typed into messages are masked automatically. (Threat #4)
- The chat closes automatically once the post is retrieved or after a set period (e.g., 7 days). Messages can be reported (see 5.6) and new messages trigger a push notification. (Threat #4, #6)

### 7.3.  Handover Confirmation
- **[Mobile]** When both parties agree on a Meet-up Location, the app shows a handover code and QR valid for a limited time. Both the owner and the finder confirm the handover in the app, which records the time and moves the post to Retrieved. (Threat #9)
- **[Web]** At the handover desk, an admin can look up or enter the code to verify the exchange, and a handover record (who, when, where) is stored for disputes. (Threat #9)

### 7.4.  No Rewards Policy
- **[Mobile]** The system has no payment or reward feature, and posts have no reward field. Chat and handover screens display a notice that TMC CampusLF does not handle money or rewards, and any such arrangement is outside the platform's responsibility. (Threat #10)

## 8.  Notification Module

### 8.1.  Push Notifications
- **[Mobile]** Sends push notifications (Firebase Cloud Messaging) for claim and evidence updates, admin approvals or rejections, new messages, moderation actions, post expiry reminders, and suggested matches. (Threat #7, #14)

### 8.2.  Email Backup
- Key events (approval, suggested match, moderation action, post about to expire) are also sent to the user's TMC email through Laravel's queued mail, so a missed push is not a missed match. (Threat #14)

### 8.3.  In-App Notification Inbox
- **[Mobile]** Every notification is saved in the database and listed in an inbox with an unread badge, so users can still see it if push delivery failed. (Threat #14)

### 8.4.  Admin Alerts
- **[Web]** The web dashboard shows live counts of pending reports, pending claim requests, pending evidence reviews, and pending registrations.

## 9.  Post Lifecycle & Matching Module

### 9.1.  Smart Match Suggestions
- When a post is created, a background job scores it against opposite-type posts using category, color, location, date range, and keywords. Posts above the threshold are suggested to both authors ("Possible matches") and shown to admins in Monitor Lost/Found Items. (Threat #7)

### 9.2.  Duplicate Post Detection
- **[Mobile]** While the user is filling in a new post, the app lists similar existing posts of the same type. The user must confirm "This is a different item" or open the existing post before submitting. (Threat #12)

### 9.3.  Automatic Archiving
- A daily scheduled task (Laravel scheduler) archives active posts after a set period (default 30 days, adjustable by admins up to 60). Authors get a reminder before expiry with a "Keep active" option. Archived posts leave the feed but remain in the database for admin records. For unclaimed found items, the notice recommends turning them over to the official TMC lost and found office. (Threat #11, #16)

## 10.  Security, Accessibility & Compliance Module

### 10.1.  Data & Transport Security
- HTTPS everywhere in deployment; passwords hashed; all queries go through Eloquent/query-builder parameter binding (no raw string SQL); Blade output is escaped against XSS; CSRF tokens on web forms; mass-assignment protection; and all inputs validated with Laravel Form Requests on both web and API. (Threat #5)

### 10.2.  Anti-Spam & Rate Limiting
- Per-user daily limits on posts (default 5), comments, claims, and reports; API throttling per IP and per account; blocked-words filter; and suspension of abusive accounts (see 5.5). Limits are adjustable in System Settings. (Threat #2)

### 10.3.  Performance & Scalability
- Paginated API responses, database indexes, eager loading to avoid slow queries, resized thumbnails for the feed, and queued jobs for email and matching. The system is deployable on any PHP/MySQL host and can be scaled up when the user base grows. (Threat #13)

### 10.4.  Accessibility
- **[Mobile]** Semantic labels for screen readers (TalkBack/VoiceOver), alt text on every image, readable fonts that follow the phone's text-size setting, sufficient color contrast, and touch targets of at least 48 px. (Threat #17)
- **[Web]** Semantic HTML, `alt` attributes, labelled form fields, keyboard navigation, and visible focus on the Blade pages, following WCAG 2.1 AA. (Threat #17)

### 10.5.  Disclaimer, Privacy & Single-Campus Scope
- The disclaimer and privacy notice are shown at registration and again on claim and handover screens, and are available in the app's Settings. They state that TMC CampusLF only helps people connect and does not keep, guarantee, or insure items, and they recommend reporting valuables to the official TMC office. Personal data is collected only as needed and is handled in line with the Data Privacy Act of 2012 (RA 10173). (Threat #4, #16)
- The system serves Trinidad Municipal College only: accounts are limited to the TMC email domain and locations to the TMC campus areas list, so no campus tagging is needed. (Threat #15)

### 10.6.  System Settings
- **[Web]** Admins manage the values the other modules rely on: TMC email domain, post/claim limits, report threshold, archive period, claim attempts, handover code validity, categories, campus areas, and the disclaimer text.

# PART 2: TECHNICAL DESIGN

## A. Architecture

| Layer | Technology | Notes |
|---|---|---|
| Admin Web Portal | Laravel (PHP) + Blade templates | Used by **admins only**. Routes in `routes/web.php`, session login with the `admin` guard, CSRF-protected forms. Covers moderation, monitoring, user and meet-up management, export, and settings. |
| Mobile App | Flutter (Dart) | Used by **users only** (students, staffs, instructors). Calls the Laravel API with `dio`/`http`; suggested packages: `provider` or `riverpod`, `flutter_secure_storage`, `image_picker`, `firebase_messaging`, `qr_flutter`, `mobile_scanner`. |
| API Layer | Laravel `routes/api.php` + Sanctum | JSON endpoints under `/api/v1` consumed only by Flutter. Token authentication, `role:user` middleware, API Resources for responses, and throttling. This is the single integration point between mobile and server. |
| Database | MySQL 8 (MySQL Workbench) | One shared schema (e.g., `tmc_campuslf`, utf8mb4). Laravel migrations create the tables; MySQL Workbench is used for the EER diagram, browsing data, running queries, and exporting backups. |
| Background Jobs | Laravel Scheduler + Queue | `schedule:run` handles daily archiving and expiry reminders; the queue (database driver) sends emails, push notifications, and runs the match scoring. |
| Notifications | Firebase Cloud Messaging + Laravel Mail | Push notifications to the app, with email to the TMC address as backup. The device token is saved at login. |
| File Storage | Laravel `storage` (public disk) | Post photos and evidence images, validated and renamed on upload. Served through `php artisan storage:link`. |
| Development Tools | Visual Studio Code, Composer, Flutter SDK | Web project (`tmc-campuslf-web`) and mobile project (`tmc-campuslf-mobile`) are developed in separate VS Code windows against the same database. |

## B. Access matrix

| Capability | Mobile (User) | Web (Admin) |
|---|---|---|
| Self-register an account | Yes (role is always user) | No (admins create accounts) |
| Log in | Users only | Admins only |
| Create, edit, delete own posts | Yes | No |
| Browse, search, comment, react | Yes | View only (monitoring) |
| Claim a found item / answer "Have you seen this item?" | Yes | No |
| Report a post, comment, or message | Yes | Reviews the reports |
| Approve or reject claims and evidence | Author approves evidence only | Yes (final approval) |
| Message the other party | Yes, after admin approval (masked) | Only reported conversations |
| Confirm handover | Yes (both parties) | Verifies code at the desk |
| Hide / delete posts, warn / suspend users | No | Yes |
| Manage users, masterlist, meet-up locations, settings | No | Yes |
| Monitor activity, audit trail, export logs | No | Yes |

## C. Database tables (MySQL)

| Table | Key fields | Purpose |
|---|---|---|
| users | id, role (user/admin), first_name, last_name, tmc_id_no, email, password, user_type, department, status, email_verified_at, terms_accepted_at | All accounts; role separates mobile users from web admins. |
| tmc_masterlist | tmc_id_no, full_name, email, user_type, department | Official TMC list used to verify registrations. |
| otp_codes | user_id/email, code_hash, purpose, expires_at, attempts | Registration and new-device OTP. |
| categories, locations | name, is_active | Item categories and TMC campus areas (admin-managed). |
| posts | user_id, type (lost/found), item_name, description, category_id, color, location_id, event_date, custody, status, hidden_reason, expires_at | Lost and found posts and their status. |
| post_images | post_id, path, alt_text | Photos with required alt text. |
| verification_questions | post_id, question, answer (encrypted) | Finder's unique questions; answers never returned by the API. |
| claim_requests | post_id, claimant_id, answers (encrypted), attempt_no, status, reviewed_by, reviewed_at | Claims on found posts and the admin decision. |
| lost_sightings | post_id, user_id, answer (yes/no), evidence_text, evidence_image, author_decision, admin_decision | "Have you seen this item?" responses with evidence. |
| comments, reactions | post_id, user_id, body / type (unique per user and post) | Post interaction. |
| reports | reportable_type, reportable_id, reporter_id, reason, status | Reports on posts, comments, and messages. |
| meetup_locations | name, description, operating_hours, is_active | Admin-managed safe handover places. |
| conversations, messages | claim_id, sender_id, body (masked), created_at | Masked in-app chat. |
| handovers | claim_id, code_hash, expires_at, owner_confirmed_at, finder_confirmed_at, verified_by, location_id | Proof that an item was returned. |
| notifications, device_tokens | user_id, type, title, body, read_at, emailed_at / fcm_token | Inbox, push, and email backup. |
| activity_logs, audit_logs | user_id/admin_id, event/action, target, reason, ip, device, created_at | Login monitoring and the admin audit trail. |
| settings | key, value | Limits, thresholds, archive period, disclaimer text. |
| personal_access_tokens | Created by Laravel Sanctum | Mobile API tokens. |

## D. Routes

| Module | Mobile API | Web admin |
|---|---|---|
| 1. Authentication | `POST /api/v1/register`, `/verify-otp`, `/login`, `/logout`, `GET/PUT /profile` | `/admin/login`, `/admin/logout`, `/admin/users` (create, suspend, import masterlist) |
| 2. Posts | `GET/POST /posts`, `GET/PUT/DELETE /posts/{id}`, `POST /posts/similar`, `GET /posts/{id}/matches` | `/admin/posts` (feed, found, lost monitoring) |
| 3. Interaction | `POST /posts/{id}/comments`, `/reactions`, `/reports` | `/admin/reports` |
| 4 & 6. Claims & sightings | `POST /posts/{id}/claims`, `POST /posts/{id}/sightings`, `PATCH /sightings/{id}` (author decision) | `/admin/claims`, `/admin/sightings` (approve or reject) |
| 5. Moderation | Notifications only | `/admin/posts/{id}/hide`, `/delete`, `/admin/users/{id}/suspend` |
| 7. Meet-up, chat, handover | `GET /meetup-locations`, `/conversations`, `/messages`, `POST /handovers/{id}/confirm` | `/admin/meetup-locations`, `/admin/handovers/verify` |
| 8. Notifications | `GET /notifications`, `POST /device-token` | `/admin/dashboard` (live counts) |
| 6. Monitoring & export | None | `/admin/activity`, `/admin/audit`, `/admin/export`, `/admin/settings` |

## E. Threat matrix

| # | Issue | Solution | Implemented in |
|---|---|---|---|
| 1 | Fake Claims (Fraud) | Finder-set verification questions with hidden, encrypted answers; limited claim attempts; admin compares answers; handover code. | 2.1, 2.2, 6.5, 7.3 |
| 2 | Spam / Fake Posts | TMC email + OTP, CAPTCHA, daily post limits, API throttling, blocked words, auto-hide on reports, suspension. | 1.1, 1.2, 3.3, 5.5, 10.2 |
| 3 | Wrong People Using the App | TMC email domain + OTP, masterlist check, new-device OTP, strict role separation (users on mobile, admins on web). | 1.1, 1.2, 1.4, 1.5 |
| 4 | Sharing Too Much Personal Info | Masked in-app chat, first name only, auto-masking of phone/email in text, no contact fields. | 1.6, 2.1, 3.1, 7.2, 10.5 |
| 5 | Hacking the System | Hashed passwords, parameter binding, HTTPS, CSRF, Sanctum tokens, input validation, secure token storage, audit trail. | 1.2, 1.3, 6.8, 10.1 |
| 6 | Inappropriate Content | Report button, auto-hide at threshold, admin review queue, blocked words, image validation, user notification. | 2.2, 3.3, 5.1 to 5.6 |
| 7 | Items Don't Get Matched | Structured category/color/location/date fields and a match-scoring job with suggestions to both sides. | 2.1, 2.4, 9.1, 8.1 |
| 8 | Danger When Meeting | Only admin-managed Meet-up Locations (e.g., security office/help desk) can be chosen; surrender-to-office option. | 2.1, 7.1 |
| 9 | No Proof Item Was Returned | Two-sided in-app confirmation with handover code/QR, optional admin verification, saved handover record. | 4.1, 7.3, 6.8 |
| 10 | Reward Scams | No money or reward feature; notice that rewards are outside the platform's responsibility. | 7.4 |
| 11 | Old Posts Piling Up | Scheduled auto-archive (default 30 days, up to 60) with reminder and "Keep active". | 9.3 |
| 12 | Same Item Posted Twice | Similar-post check with confirmation before submit. | 2.1, 9.2 |
| 13 | App Slows Down or Crashes | Pagination, indexes, eager loading, thumbnails, queued jobs, deployable on scalable hosting. | 2.4, 10.3 |
| 14 | Missing Notifications | Push + email backup + saved in-app inbox for every important event. | Module 8, 5.3 |
| 15 | Mixing Up Different Campuses | Re-scoped: single-campus system for TMC; TMC email domain and TMC campus areas only. | 1.1, 2.1, 2.4, 10.5 |
| 16 | Who's Responsible If Something Goes Wrong | Disclaimer accepted at registration and shown at claim/handover; advice to report valuables to the TMC office. | 1.1, 2.1, 9.3, 10.5 |
| 17 | Not Everyone Can Use the App Easily | Alt text, screen-reader labels, readable fonts and contrast, WCAG 2.1 AA on web. | 2.2, 10.4 |

## F. Design decisions

| Issue | Decision |
|---|---|
| Which platform can each role use? | Strict split: the Flutter mobile app is for users only and the Laravel Blade web portal is for admins only. Each side refuses the other role at login and again in server middleware. |
| Open sign-up versus campus-only access | Sign-up needs the TMC email, an OTP, and a masterlist match. Admins can also create accounts, and unmatched sign-ups wait for approval instead of being rejected outright. |
| Privacy versus easy contact | Users never see each other's contact details. They talk through a masked chat that opens only after admin approval and closes after the handover. |
| Who decides a disputed claim? | The admin makes the final decision after comparing answers; the author never sees the claimant's answers before approval, and claimants never see the correct answers. |
| Photos that give away the answers | Users are warned during upload and admins can hide or ask the author to edit; verification questions are locked once a claim is pending. |
| Hide first or delete first? | Reported posts are hidden first. Permanent deletion needs an admin decision, a stated reason, a notification to the author, and an audit entry. |
| Admin access to private chats | Admins can read a conversation only after a user has reported it, and that access is logged. |
| Rewards and money | Not supported. Rewards are outside the platform to avoid scams and liability. |
| Multi-campus tagging | Not needed because the system is TMC-specific. The risk is handled by limiting accounts and locations to TMC. |
| Valuables such as IDs, phones, and wallets | The finder is encouraged to surrender them to the TMC office; custody is shown on the post. |
| Liability | TMC CampusLF is a facilitator, not a custodian. The disclaimer is accepted at registration, with the date saved. |
| Data retention | Posts are archived, not erased; logs and exports exclude passwords and answers. Retention periods are admin settings. |

## G. Setup checklist

1. Create the schema in MySQL Workbench (`CREATE DATABASE tmc_campuslf CHARACTER SET utf8mb4;`) and keep the EER diagram in the project folder.
2. Create the web project in VS Code: `composer create-project laravel/laravel tmc-campuslf-web`, set `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `MAIL_*`, and `TMC_EMAIL_DOMAIN` in `.env`.
3. Install Sanctum (`php artisan install:api`), create migrations for the tables in the database design, and seed the first admin, categories, campus areas, and meet-up locations.
4. Build the Blade admin pages (login, dashboard, users, reports, claims, meet-ups, logs, export, settings) with the `admin` guard and `role:admin` middleware.
5. Build the `/api/v1` endpoints with `role:user` middleware, Form Requests, API Resources, and throttling.
6. Create the Flutter project: `flutter create tmc_campuslf_mobile`; set the API base URL in one config file (Android emulator: `http://10.0.2.2:8000/api/v1`; real phone: the PC's LAN IP).
7. Run the system: `php artisan serve --host=0.0.0.0`, `php artisan queue:work`, `php artisan schedule:work`, then `flutter run`.
8. Test each module with the role matrix: a user account must fail on the web portal, an admin account must fail on the mobile app, and a user must never receive another user's verification answers from the API.

# PART 3: UI / UX

## H. Design system

| Element | Specification |
|---|---|
| Mood | Clean, friendly, and trustworthy campus look: white cards on a warm off-white background, deep red as the main color (matching the TMC CampusLF document), with a gold accent for highlights. |
| Colors | Primary deep red `#A31621`; Primary dark `#7A0F18`; Accent gold `#F2B705`; Background `#FAF7F5`; Surface white `#FFFFFF`; Text `#1F1A1A`; Muted text `#6B6262`; Border `#E6DFDC`. Lost tag red `#C62828`; Found tag green `#1B7F6B`; Success `#2E7D32`; Warning `#ED8B00`; Info `#1E6FB8`. |
| Typography | Headings: Poppins SemiBold. Body: Inter Regular. Sizes: title 22, section 18, body 15, caption 12. In Flutter use `google_fonts`; on the web load the same fonts from Google Fonts. Text must scale with the phone's font-size setting. |
| Shape & spacing | 8-pt spacing grid (8, 16, 24). Cards and inputs: 12 px corner radius; buttons: 12 px; chips: fully rounded. Soft shadow on cards only. Screen side padding 16 px. |
| Buttons | **Primary:** filled deep red, white text, 48 px high, full width on mobile. **Secondary:** white with red outline. **Text button:** red text, no border. **Destructive:** filled `#C62828` (Delete, Reject, Suspend), always asks for confirmation. **Disabled:** grey `#D9D2CF`. Loading state shows a small spinner and blocks double taps. |
| Input fields | Outlined fields with a floating label, 48 px high, red focus border, red error text under the field, helper text in grey, and a show/hide icon on password fields. Required fields are marked with an asterisk. Errors appear after the user leaves the field, not while typing. |
| Feedback | Snackbar (mobile) or toast (web) for success/failure; shimmer placeholders while loading; friendly empty states with an icon and one action button ("No posts yet. Create the first post"); pull-to-refresh on every list. |
| Icons & images | Material Symbols (Flutter `Icons`, and Material Icons on web). Post photos use a 4:3 crop with rounded corners; avatars show initials in a colored circle. |
| Accessibility | Contrast at least 4.5:1, touch targets at least 48 px, every icon button has a tooltip/semantic label, every image has alt text, never rely on color alone (Lost/Found tags also carry text). |

## H.1 Mobile screens (users only)

| Screen | Spec |
|---|---|
| Splash & Welcome | TMC CampusLF logo on deep red, tagline "Lost something? Found something? Let's connect it at TMC." Buttons: **Log in** (primary), **Create account** (secondary). Small link: Terms & Disclaimer. |
| Register (3 steps) | Step bar at top. **Step 1 Account:** First name, Last name, TMC ID number, TMC email, User type dropdown (Student/Staff/Instructor), Department dropdown, Password, Confirm password; CAPTCHA. **Step 2 Verify:** six separate OTP boxes, "Resend code" with 60 s countdown. **Step 3 Terms:** scrollable disclaimer, required checkbox "I understand TMC CampusLF only connects people and does not keep items", button **Create account**. |
| Login | TMC email, Password (show/hide), **Log in** button, "Forgot password?" link. Admin accounts see: "Admins use the web portal." |
| Bottom navigation (after login) | Five tabs: **Home** (feed), **Search**, **+ Post** (raised round center button in gold), **Inbox** (notifications and chats, with unread badge), **Profile**. The active tab is deep red with a label; the others are grey. |
| Home (Community Feed) | Top bar: TMC CampusLF logo, filter icon. Chips under it: **All \| Lost \| Found**. Cards in a vertical list: photo, Lost/Found tag, item name, category chip, location, date, first name of poster, reaction and comment counts. Pull to refresh, infinite scroll. A "Possible matches for you" strip appears on top when the system finds matches. |
| Search & Filters | Search field with clear button; bottom sheet filter with Type (Lost/Found), Category chips, Color chips, Location dropdown, Date range, Status (Active/Retrieved), buttons **Apply** and **Reset**. |
| Post Details | Photo carousel with dots, Lost/Found tag, status badge, details list (category, color, location, date, description), poster's first name, reaction row (Like, Haha, Angry, Sad), comments list with an input at the bottom, and a three-dot menu (Report, and Edit/Delete for the owner). **Found post:** primary button **This is mine. Claim item**. **Lost post:** text "Have you seen this item?" with two buttons, **Yes** and **No**. |
| Claim Item | Shows the finder's verification questions (1 to 3), each with a multiline answer field, a warning "Wrong answers use up one of your 3 attempts", and **Submit claim**. After sending, a status screen shows "Waiting for admin review". |
| Seen It (Yes) Evidence | Short form: description of where and when it was seen, photo upload (camera/gallery), **Send to owner** button. |
| Create Post (4 steps) | Segmented toggle **I lost something \| I found something**. Step 1 Details: item name, category, color, location dropdown, date picker, description. Step 2 Photos: add up to 5 photos, each with an image description field. Step 3 (found only): verification questions with answers, and custody choice (With me / Surrendered to TMC office). Step 4 Review: list of **similar existing posts** and the button **This is a different item, post it**. Back and Next buttons on every step; draft is kept if the user leaves. |
| Inbox | Two tabs: **Notifications** (approvals, rejections, moderation notices, matches, expiry reminders) and **Chats** (approved conversations only). Unread items have a red dot; swipe to mark as read. |
| Chat & Handover | Message bubbles with first names only; banner "Do not share phone numbers. Meet only at a TMC meet-up location." Top buttons: **Choose meet-up location** (list from admins with hours), **Handover** . The handover screen shows a large QR and code with a countdown, and the button **Confirm handover**. Footer notice: no rewards or payments through the app. |
| My Posts | Tabs Active / Claim Pending / Retrieved / Archived; each card has Edit, Delete, **Mark as Retrieved**, and Keep Active (for expiring posts). |
| Profile & Settings | Avatar with initials, name, department, edit profile, change password, notification settings, Terms & Disclaimer, Privacy Notice, **Log out**. |

## H.2 Web admin pages (admins only)

| Page | Spec |
|---|---|
| Login | Centered card on a deep red background: logo, Email, Password (show/hide), CAPTCHA, **Sign in** button. Shows "Users must use the mobile app" if a user account is used. |
| Layout | Fixed left sidebar (deep red, white icons and labels, collapsible on small screens) and a white top bar with a global search field, notification bell with counter, and the admin menu (Profile, Log out). Breadcrumbs under the top bar. Content on a warm off-white background with white cards. |
| Sidebar menu | **Dashboard**; **Moderation** (Reported Posts); **Claims & Returns** (Found Items, Lost Items, Handovers); **Community Feed**; **Users** (All Users, Registrations, Masterlist Import); **Meet-up Locations**; **Monitoring** (Login/Logout Activity, User Interactions); **Audit Trail**; **Export**; **Settings**. |
| Dashboard | Row of KPI cards (Active posts, Pending claims, Pending reports, Retrieved this month, New users). Below: "Needs your attention" table with quick buttons, a line chart of posts per week, and a donut of Lost vs Found. |
| Data tables (all lists) | Search box, filter dropdowns (status, type, date range), sortable columns, status pills, row action buttons (View, Hide, Delete), pagination (15 per page), and **Export CSV** at the top right. |
| Reported Posts | Table with reason, number of reporters, and post preview. **View** opens a side panel with the full post and buttons **Dismiss**, **Hide**, **Delete**, **Warn author**, **Suspend author**. Delete and Suspend open a confirmation dialog that requires a written reason, which is sent to the author. |
| Claim Review (Found Items) | Two-column card: left shows the post, photos, and the finder's correct answers; right shows the claimant's answers and attempt count. Under it: a dropdown **Meet-up Location** and buttons **Approve claim** (green) and **Reject claim** (red, with reason). |
| Lost Items (Yes answers) | Shows the lost post and the evidence submitted by the person who saw it, side by side, with **Approve return** and **Reject** buttons. |
| Users | Table with name, TMC ID, type, status, last login. Buttons: **Add user** (form), Edit, Reset password, Suspend/Reactivate. **Import masterlist** accepts a CSV with a preview before saving. |
| Meet-up Locations | Card grid or table with name, description, operating hours, and an Active toggle; **Add location** opens a modal form. |
| Settings | Grouped forms with **Save changes**: Email domain, Limits (posts per day, claim attempts), Report threshold, Archive days, Handover code validity, Disclaimer text. |

# PART 4: CONNECTION (MOBILE, WEB, MYSQL)

## Request flow

| Step | What happens |
|---|---|
| 1 | **Flutter app** sends an HTTPS request with JSON to the Laravel API (e.g., `POST /api/v1/login`). After login, every request carries the header `Authorization: Bearer <token>`. |
| 2 | **Laravel** (`routes/api.php`) validates the token (Sanctum), checks `role:user`, validates the input with a Form Request, and runs the logic in a controller/service. |
| 3 | **Laravel to MySQL:** Eloquent models use the PDO connection in `.env` (`DB_HOST`, `DB_PORT=3306`, `DB_DATABASE=tmc_campuslf`) to read and write tables such as `posts` and `claim_requests`. |
| 4 | **Laravel to Flutter:** the controller returns a JSON Resource (`{ "data": ... }` or `{ "message", "errors" }`). Flutter turns it into Dart models and updates the screen. |
| 5 | **Admin web** (`routes/web.php`): the admin's browser requests a Blade page; Laravel checks the session and `role:admin`, queries the same MySQL database, and returns the rendered HTML. Admin actions (approve, hide) change rows that the mobile app reads on its next request. |
| 6 | **MySQL Workbench** connects directly to the same MySQL server (port 3306) for design, inspection, and backup only. The apps never talk to Workbench; they talk to MySQL through Laravel. |
| 7 | **Background:** the queue worker and scheduler (Laravel) write notifications and send push (FCM) and email, so a change made on the web reaches the user's phone. |

```
# web/.env  (Laravel)
APP_URL=http://192.168.1.10:8000      # your PC's LAN IP when testing on a real phone
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tmc_campuslf
DB_USERNAME=root
DB_PASSWORD=your_password
QUEUE_CONNECTION=database
TMC_EMAIL_DOMAIN=yourcollegedomain.edu.ph   # replace with the real TMC email domain

# mobile/lib/core/config.dart  (Flutter)
// Android emulator:  http://10.0.2.2:8000/api/v1
// Real phone (same Wi-Fi): http://192.168.1.10:8000/api/v1
const String kBaseUrl = 'http://10.0.2.2:8000/api/v1';
```

## MySQL Workbench

1. Open MySQL Workbench, click the **+** beside MySQL Connections, name it "TMC CampusLF Local", host `127.0.0.1`, port `3306`, user `root`, then **Test Connection**. (The MySQL server can come from MySQL Installer, XAMPP, or Laragon.)
2. Run `CREATE DATABASE tmc_campuslf CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;` in a SQL tab and refresh the Schemas panel.
3. In the Laravel folder run `php artisan migrate --seed`. The tables now appear under `tmc_campuslf` in Workbench (right click, Refresh All).
4. Create the EER diagram: **Database > Reverse Engineer**, pick the connection and the schema, and Workbench draws all tables and foreign keys. Save the `.mwb` file in the project's `docs/` folder.
5. To change the structure, edit Laravel migrations (not the tables in Workbench), then run `php artisan migrate`. This keeps the code and database in sync.
6. Use Workbench's **Server > Data Export** for backups, and the table viewer to check that an admin action (for example, hiding a post) changed the `status` column that the app reads.

## Integration tests

| Test | Expected |
|---|---|
| Register on the mobile app | A row appears in `users` with role `user` and a row in `otp_codes`. |
| Log in on the web with that user | Refused. Log in on the mobile app with an admin account: also refused. |
| Create a found post on the mobile app | Rows in `posts`, `post_images`, and `verification_questions`; the post shows in the app's feed and in the admin's Found Items. |
| Claim it from another user, approve it on the web | `claim_requests.status` becomes approved; the claimant gets a notification and the chat opens. |
| Hide a post on the web | `posts.status` changes to hidden; it disappears from the mobile feed after refresh; the author is notified. |
