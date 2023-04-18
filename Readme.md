A [Telegram bot](https://core.telegram.org/bots/api) using the [OpenAI API](https://platform.openai.com/docs/api-reference/) to generate a swift interaction experience.

## Setup instructions

1. Put this on a server that is accessible from the Internet
    1. Rename the file `config_template.json` in the `chats/` folder to `config.json`.
    2. Ensure the root folder, the `chats/` folder, and the file `chats/config.json` are writable by the web server.
        ```bash
        chmod +w -R .
        ```
    3. Set your timezone in the `config.json` file, e.g.
        ```json
        "TIME_ZONE": "Europe/Rome"
        ```
        You can find the list of valid timezone identifiers [here](https://www.php.net/manual/en/timezones.php).
2. Set up the **Telegram bot**
    1. Create a Telegram bot using [BotFather](https://t.me/botfather)
    2. Get the bot's token from BotFather. In the `config.json`, put the token to the `TELEGRAM_BOT_TOKEN` variable.
    3. Generate a random secret token that you will use to authenticate requests to this script. You can use the following command to generate a random token:
        ```bash
        head -c 160 /dev/urandom | base64 | tr -d "=+/" | cut -c -128
        ```
    4. In the `config.json` file, put the secret token to `TELEGRAM_BOT_SECRET`.
    5. Set up a [webhook](https://core.telegram.org/bots/api#setwebhook) for the bot using
        ```bash
        curl https://api.telegram.org/bot<token>/setWebhook?url=<url>&secret_token=<secret_token>
        ```
    6. Create a Telegram chat with the bot and send a message to it.
    7. It will reply with a message that contains the chat ID. In the `config.json` file, put the chat ID to `TELEGRAM_ADMIN_CHAT_ID`.
3. Set up the **OpenAI API** connection
    1. Create an [OpenAI account](https://beta.openai.com/signup)
    2. Create an [OpenAI API key](https://beta.openai.com/account/api-keys) (you will have to set up the billing)
    3. Put the key to `OPENAI_API_KEY` in the `config.json` file.
4. Enjoy :)