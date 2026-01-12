# 🚀 LocalMan

A Postman-like standalone PHP application that runs as a single file. Send API requests, capture webhooks, and track your API testing history - all in one simple, beautiful interface!

## ✨ Features

- **📤 Send API Requests**: Full support for GET, POST, PUT, PATCH, and DELETE methods
- **🎯 Custom Headers & Body**: Add custom headers and request bodies for any API call
- **📨 Webhook Capture**: Built-in webhook endpoint to receive and inspect incoming webhooks
- **🔄 Auto-Reload**: Webhook page automatically refreshes when new webhooks arrive
- **📝 Request History**: Track all your API requests with status codes, response times, and timestamps
- **💾 Persistent Storage**: All data stored in local files (no database required)
- **🎨 Beautiful UI**: Modern, responsive interface built with Tailwind CSS
- **⚡ Single File**: Everything you need in just one PHP file

## 📋 Requirements

- PHP 7.4 or higher
- cURL extension enabled (usually enabled by default)
- Write permissions for the application directory

## 🚀 Installation

1. **Download the file**
   ```bash
   git clone https://github.com/madanielsen/localman.git
   cd localman
   ```

2. **Start a PHP server**
   ```bash
   php -S localhost:8000
   ```

3. **Open in your browser**
   ```
   http://localhost:8000
   ```

That's it! LocalMan will automatically create the necessary storage directories on first run.

## 📖 Usage

### Sending API Requests

1. Navigate to the **API Request** tab
2. Select your HTTP method (GET, POST, PUT, PATCH, DELETE)
3. Enter the target URL
4. (Optional) Add custom headers (one per line, e.g., `Content-Type: application/json`)
5. (Optional) Add a request body for POST/PUT/PATCH requests
6. Click **Send Request**
7. View the response status, headers, and body

**Example Headers:**
```
Content-Type: application/json
Authorization: Bearer your-token-here
X-Custom-Header: custom-value
```

**Example JSON Body:**
```json
{
  "name": "John Doe",
  "email": "john@example.com"
}
```

### Capturing Webhooks

1. Navigate to the **Webhooks** tab
2. Copy your unique webhook URL displayed at the top
3. Configure your service to send webhooks to this URL
4. Watch as webhooks appear automatically (the page refreshes every 2 seconds)
5. Inspect the webhook method, headers, query parameters, and body

### Viewing History

1. Navigate to the **History** tab
2. View all your previous API requests with:
   - HTTP method
   - Request URL
   - Status code
   - Response time
   - Timestamp

## 🗂️ Storage

LocalMan stores all data in local JSON files:
- **Webhooks**: `storage/webhooks/*.json`
- **Requests**: `storage/requests/*.json`

The application automatically:
- Creates storage directories on first run
- Keeps the last 50 entries for each type
- Cleans up old entries automatically

## 🎨 Technology Stack

- **PHP**: Backend logic and request handling
- **Tailwind CSS**: Modern, utility-first CSS framework (loaded via CDN)
- **Vanilla JavaScript**: Auto-reload functionality for webhooks
- **File-based Storage**: No database required

## 🔒 Security Notes

- LocalMan is designed for local development and testing
- Do not expose LocalMan to the public internet without proper authentication
- The storage directory should have appropriate write permissions
- Consider using `.htaccess` or nginx rules to restrict access in production environments

## 🤝 Contributing

Contributions are welcome! This is a simple, single-file application meant to be easy to understand and modify.

## 📄 License

MIT License - feel free to use, modify, and distribute as you wish.

## 💡 Use Cases

- **API Development**: Test your API endpoints during development
- **Webhook Testing**: Debug webhook integrations without deploying
- **API Exploration**: Explore third-party APIs quickly
- **Learning**: Understand how APIs work with real-time feedback
- **Local Testing**: Test API changes before deploying to production

## 🎯 Why LocalMan?

- **No Installation Hassle**: Just one PHP file, no complex setup
- **Lightweight**: No external dependencies except PHP
- **Offline Capable**: Works completely offline (except for Tailwind CSS CDN)
- **Privacy**: All data stays on your machine
- **Fast**: Instant setup, instant results

---

Made with ❤️ for developers who need quick API testing without the bloat.
