# PU Anonymous Stateless Bot

Privacy-first anonymous Telegram community bot written in plain PHP.

No MySQL, PostgreSQL, SQLite, Redis, MongoDB, user table, message table, or admin panel. Telegram messages are the moderation queue and publication history.

## Setup

1. Create a Telegram bot with BotFather.
2. Create the main channel.
3. Create a private moderation group.
4. Create and connect the channel discussion group in Telegram.
5. Add the bot as admin to the channel, moderation group, and discussion group.
6. Copy `.env.example` to `.env`.
7. Fill `BOT_TOKEN`, `BOT_USERNAME`, `CHANNEL_ID`, `MODERATION_GROUP_ID`, `DISCUSSION_GROUP_ID`, `ADMIN_IDS`, `APP_SECRET`, and `WEBHOOK_SECRET`.
8. Create a Gemini API key in Google AI Studio and set `GEMINI_API_KEY`.
9. Install dependencies:

```bash
composer install --no-dev
```

10. Run locally for testing:

```bash
php -S 0.0.0.0:8080 -t .
```

11. Configure Telegram webhook. This project uses root `index.php` as the webhook file:

```bash
curl "https://api.telegram.org/bot$BOT_TOKEN/setWebhook" \
  -d "url=$WEBHOOK_URL" \
  -d "secret_token=$WEBHOOK_SECRET"
```

Or fill `.env` and run:

```bash
php setup.php
```

For Docker:

```bash
docker build -t pu-anonymous .
docker run --env-file .env -p 8080:8080 pu-anonymous
```

## Required Tests

Test a first-time user without `/start`: send `Does anyone know when scholarship applications open?`. The bot should answer with checking status and create a moderation group item.

Test `/start`: it should only show a short welcome message. A later normal message must enter the same anonymous submission flow.

Test approval: admin presses `Approve`; the channel receives `PU Anonymous` plus the submitted content, with no username, name, user ID, or forwarded-from metadata.

Test rejection: admin presses `Reject`; no public post is created.

Test anonymous comments: after a post is published, the bot adds `Reply anonymously`. The user opens it, replies to the bot instruction message, and the comment goes through moderation.

Test delete: after approval the original sender receives `Delete this post`. The signed button lets only that sender delete the channel post.

## Admin Editing

To edit text before approval, an admin replies to the moderation message:

```text
/edit Corrected public text
```

Then press `Approve`.

## Privacy Rules

The bot does not store sender profiles or message history. Logs intentionally avoid user IDs, usernames, and content. Sender IDs are used only in memory and inside signed/encrypted tokens needed for Telegram callbacks.

Do not add a database unless the privacy model is intentionally changed.
