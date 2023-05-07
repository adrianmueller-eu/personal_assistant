A [Telegram bot](https://core.telegram.org/bots/api) using the [OpenAI API](https://platform.openai.com/docs/api-reference/) to generate a swift interaction experience.

## Features

#### Implemented ✅
- [x] Connect to Telegram API and OpenAI API
- [x] Store the chat history of the current session
- [x] Admin commands for adding and removing users

General bot
- [x] Several presets for different use cases

Mental health bot
- [x] System prompt to have the bot interact in an emotionally attuned way
- [x] Generate a profile for the user and update it after each session

#### Ideas for the future 🔮
- [ ] Use a database instead of files (the `chats` folder)
- [ ] Use abstract classes to connect to different platforms (e.g. [Whatsapp](https://business.whatsapp.com/developers/developer-hub), [Discord](https://discord.com/developers/applications), [Matrix](https://matrix.org/faq/#bots), etc.) or to other AIs (any examples here would be outdated tomorrow)
- [ ] Enable sending images to the AI
- [ ] ASR and TTS

General bot
- [ ] Enable to search, scrape webpages, and send them back to the AI
- [ ] Various calendar integrations
- [ ] Travel planning and booking

Mental health bot
- [ ] Provide different types of sessions (e.g. guided meditation, journaling, CBT, IFS, etc.) and allow the user to choose
- [ ] Send reminders to the user to have a session
- [ ] Provide a way to schedule sessions
- [ ] Find a way to measure and track the user's progress (e.g. mood, stress, etc.)
- [ ] Forward to social workers or an actual therapist (e.g. some database (?) that can be searched by location, specializations, etc.)
- [ ] Crisis support and intervention (e.g. if the user is in a crisis, the bot can send a message to the user's emergency contacts)
- [ ] Privacy and security (e.g. encrypt the user's data, use a VPN, etc.)

## Setup instructions

1. Put this on a server that is accessible from the Internet
    1. Copy the file `chats/config_template.json` to create `chats/config.json`.
        ```bash
        cp chats/config_template.json chats/config.json
        ```
    2. Ensure the server can create new folders and files, and modify `chats/config.json`.
        ```bash
        chmod +w -R .
        ```
    3. Set your timezone in the `chats/config.json` file, e.g.
        ```json
        "TIME_ZONE": "Europe/Rome"
        ```
        You can find the list of valid timezone identifiers [here](https://www.php.net/manual/en/timezones.php).
    4. Choose the bot you want to use by (un)commenting the respective line at the beginning of [index.php](index.php). E.g., for the mental health bot change the start of file to
        ```php
        // Set here the bot you want to use
        // require_once __DIR__."/bots/general.php";
        require_once __DIR__."/bots/mental_health.php";
        ```
2. Set up the **Telegram bot**
    1. Create a Telegram bot using [BotFather](https://t.me/botfather)
    2. Get the bot's token from BotFather. In the `chats/config.json`, put the token to the `TELEGRAM_BOT_TOKEN` variable.
    3. Generate a random secret token that you will use to authenticate requests to this script. You can use the following command to generate a random token:
        ```bash
        head -c 160 /dev/urandom | base64 | tr -d "=+/" | cut -c -128
        ```
    4. In the `chats/config.json` file, put the secret token to `TELEGRAM_BOT_SECRET`.
    5. Set up a [webhook](https://core.telegram.org/bots/api#setwebhook) for the bot using
        ```bash
        curl https://api.telegram.org/bot<token>/setWebhook?url=<url>&secret_token=<secret_token>
        ```
    6. Create a Telegram chat with the bot and send a message to it.
    7. It will reply with a message that contains the chat ID. In the `chats/config.json` file, put the chat ID to `TELEGRAM_ADMIN_CHAT_ID`.
3. Set up the **OpenAI API** connection
    1. Create an [OpenAI account](https://beta.openai.com/signup)
    2. Create an [OpenAI API key](https://beta.openai.com/account/api-keys) (you will have to set up the billing)
    3. Put the key to `OPENAI_API_KEY` in the `chats/config.json` file.
4. Enjoy :)
