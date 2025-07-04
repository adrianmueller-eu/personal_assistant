A [Telegram bot](https://core.telegram.org/bots/api) using the [OpenAI API](https://platform.openai.com/docs/api-reference/) or [Anthropic API](https://docs.anthropic.com/en/api/) to generate a swift interaction experience.

## Features

#### Implemented ✅
- [x] Connect to Telegram API and OpenAI API
- [x] Store the chat history of the current session
- [x] Admin commands for adding and removing users
- [x] Sending images
- [x] Requesting images (using DALLE)
- [x] ASR and TTS (using OpenAI)
- [x] Sending reminders to the user (using Cron)
- [x] Support for Anthropic API
- [x] Support for OpenRouter API

General bot
- [x] Several presets for different use cases

Mental health bot
- [x] System prompt to have the bot interact in an emotionally attuned way
- [x] Generate a profile for the user and update it after each session
- [x] Provide different types of sessions (e.g. guided meditation, journaling, CBT, IFS, etc.) and allow the user to choose

#### Ideas for the future 🔮
- [ ] Use a database instead of files (the `chats` folder)
- [ ] Connect to other platforms than Telegram (e.g. ~~[Whatsapp](https://business.whatsapp.com/developers/developer-hub)~~, [Discord](https://discord.com/developers/applications), [Matrix](https://matrix.org/faq/#bots), etc.) or to other AIs (any examples here would be outdated tomorrow)

General bot
- [ ] Enable to search, scrape webpages, and send them back to the AI
- [ ] Various calendar integrations
- [ ] Travel planning and booking

Mental health bot
- [ ] Provide a way to schedule sessions
- [ ] Find a way to measure and track the user's progress (e.g. mood, stress, etc.)
- [ ] Forward to social workers or an actual therapist (e.g. some database (?) that can be searched by location, specializations, etc.)
- [ ] Crisis support and intervention (e.g. if the user is in a crisis, the bot can send a message to the user's emergency contacts)
- [ ] Privacy and security (e.g. encrypt the user's data, use a VPN, etc.)

## Setup instructions

1. Put this on a server that is accessible from the Internet
    1. Choose the bot you want to use by (un)commenting the respective line at the beginning of [index.php](index.php). E.g., for the mental health bot change it to
        ```php
        // Set here the bot you want to use
        // require_once __DIR__."/bots/general.php";
        require_once __DIR__."/bots/mental_health.php";
        ```
    2. Copy the file `chats/config_template.json` to create `chats/config.json`. It is used to manage users and can be used to set variables. Also ensure the server can create new folders and files (e.g. user files, log files), and modify `chats/config.json`. Apply minimal necessary permissions:
        ```bash
        cp chats/config_template.json chats/config.json && chmod 755 . && chmod -R 644 ./chats && chmod 755 ./chats && chmod 755 ./logs
        ```
    3. The bot will need access to multiple variables. You can use the `chats/config.json` file to configure the variables or save them as environment variables, e.g. using `SetEnv <KEY> <VALUE>` in the `.htaccess` or using the ["Secrets" function in Replit](https://docs.replit.com/programming-ide/workspace-features/secrets).
2. Set up the **Telegram bot**
    1. Create a Telegram bot using [BotFather](https://t.me/botfather)
    2. Get the bot's token from BotFather. Put the token into the `TELEGRAM_BOT_TOKEN` variable.
    3. Generate a strong random secret token that you will use to authenticate requests to this script. You can use the following command to generate a random token:
        ```bash
        head -c 160 /dev/urandom | base64 | tr -d "=+/" | cut -c -128
        ```
    4. Put the secret token in the `TELEGRAM_BOT_SECRET` environment variable. Never share this token or commit it to version control.
    5. Set up a [webhook](https://core.telegram.org/bots/api#setwebhook) for the bot using
        ```bash
        curl https://api.telegram.org/bot<token>/setWebhook?url=<url>&secret_token=<secret_token>
        ```
    6. Send a message with `chatid` to the bot. It will reply with a message that contains your chat ID.
    7. Put the chat ID into `TELEGRAM_ADMIN_CHAT_ID`.
3. Set up the **OpenAI/Anthropic API** connection (since Anthropic doesn't support audio processing yet, you might want an OpenAI connection anyway)
    1. Create an [OpenAI account](https://beta.openai.com/signup)
    2. Create an [OpenAI API key](https://beta.openai.com/account/api-keys) (you will have to set up the billing)
    3. Put it into the `OPENAI_API_KEY` variable.
    4. Create an [Anthropic account](https://console.anthropic.com)
    5. Create an [Anthropic API key](https://console.anthropic.com/settings/keys) (again, needs billing)
    6. Put it into `ANTHROPIC_API_KEY`.
4. Enjoy :)
