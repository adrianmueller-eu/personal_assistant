A [Telegram bot](https://core.telegram.org/bots/api) using the [OpenAI API](https://platform.openai.com/docs/api-reference/) to generate a swift interaction experience.

## Setup instructions

1. Put this on a server that is accessible from the Internet
2. Set up the **Telegram bot**
    1. Create a Telegram bot using [BotFather](https://t.me/botfather)
    2. Get the bot's token from BotFather and put it into your .htaccess file:
        ```
        SetEnv TELEGRAM_BOT_TOKEN <token>
        ```
    3. Generate a random secret token that you will use to authenticate requests to this script. You can use the following command to generate a random token:
        ```bash
        head -c 160 /dev/urandom | base64 | tr -d "=+/" | cut -c -128
        ```
    4. Put the secret token into your .htaccess file, too:
        ```
        SetEnv TELEGRAM_BOT_SECRET <secret_token>
        ```
    5. Set up a [webhook](https://core.telegram.org/bots/api#setwebhook) for the bot using
        ```bash
        curl https://api.telegram.org/bot<token>/setWebhook?url=<url>&secret_token=<secret_token>
        ```
    6. Create a Telegram chat with the bot and send a message to it
    7. It will reply with a message that contains the chat ID, which you need to put into your .htaccess file:
        ```
        SetEnv CHAT_ID_ADMIN <chat_id>
        ```
3. Set up the **OpenAI API** connection
    1. Create an [OpenAI account](https://beta.openai.com/signup)
    2. Create an [OpenAI API key](https://beta.openai.com/account/api-keys) (you will have to set up the billing)
    3. Put the key into your .htaccess file:
        ```
        SetEnv OPENAI_API_KEY <key>
        ```
4. Optionals
    1. Set your timezone in your .htaccess file:
        ```
        SetEnv TIME_ZONE <timezone>
        ```
        You can find the list of valid timezone identifiers [here](https://www.php.net/manual/en/timezones.php).
5. Enjoy :)