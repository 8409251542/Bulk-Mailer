# Bulk Mailer Pro – WordPress Plugin

**Bulk Mailer Pro** is a lightweight WordPress plugin that allows you to:  
- Add **multiple SMTP accounts**  
- Send **bulk emails** with automatic **SMTP rotation**  
- Track **delivery reports** (success/failed)  

Perfect for newsletters, announcements, and outreach campaigns.  

---

## ✨ Features
- 🔑 Multiple SMTP support (Gmail, Outlook, Zoho, Amazon SES, SendGrid, etc.)
- 🔄 Automatic SMTP rotation for bulk sending
- 📊 Mail reports (status: sent, failed, error message)
- 📥 Import bulk email list (CSV upload)
- 🛡️ Secure with PHPMailer integration
- ⚡ Lightweight & fast (no external dependencies)

---

## 📦 Installation
1. Upload the plugin to the `/wp-content/plugins/` directory, or install directly via WordPress.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. You will now see a new menu **Bulk Mailer Pro** in your WordPress Admin sidebar.

---

## ⚙️ Setup
### 1. Add SMTP Accounts
- Navigate to **Bulk Mailer Pro → SMTP Settings**
- Click **Add New SMTP** and enter:
  - Host (e.g., `smtp.hostinger.com`)
  - Port (465/587)
  - Encryption (SSL/TLS)
  - Username (full email address)
  - Password  
- You can add multiple SMTP accounts.

### 2. Send Bulk Email
- Go to **Bulk Mailer Pro → Send Mail**
- Upload a **CSV list of recipients** OR enter emails manually
- Enter **Subject** and **Message** (supports HTML)
- Click **Send Bulk Mail** → plugin will rotate SMTPs automatically

### 3. View Reports
- Go to **Bulk Mailer Pro → Reports**
- View email logs:
  - Recipient email
  - Subject
  - SMTP used
  - Status (sent/failed)
  - Error message (if failed)

---

## 📊 Example Mail Report Entry
| Time                | Recipient              | Subject         | SMTP ID | Status  | Error Message          |
|---------------------|------------------------|-----------------|---------|---------|------------------------|
| 2025-08-20 11:33:15 | ravi.yoof@gmail.com    | Hello Test Mail | 1       | Failed  | wp_mail returned false |

---

## 🔧 Recommended SMTP Providers
- **Amazon SES** – cheapest, scalable, high deliverability
- **SendGrid** – easy setup, great for bulk
- **Mailgun** – good for transactional & marketing emails
- **Zoho Mail / Gmail / Outlook** – good for small campaigns

---

## ⚠️ Notes & Best Practices
- Always verify your domain (SPF, DKIM, DMARC) to avoid **spam folder**.
- Warm up new domains → don’t send thousands of emails on Day 1.
- Use **Amazon SES / SendGrid** for serious bulk campaigns.

---

## 📝 License
This plugin is released under the **GPL v2 or later** license.
