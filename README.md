# MetForm → GetResponse Integration (WordPress Plugin)

A lightweight WordPress plugin that integrates **MetForm** submissions with **GetResponse**.  
When a user submits a MetForm form, their **name** and **email** are automatically added to your GetResponse campaign list.

---

## 🚀 Features
- Automatically send form submissions (name & email) to GetResponse.
- Admin settings page in WordPress:
  - API Key
  - Campaign ID
  - Enable/disable debug logging
- Clean code, extendable for custom fields.

---

## 📦 Installation

1. Download or clone this repository into your WordPress `wp-content/plugins/` directory:
   ```bash
   git clone https://github.com/your-repo/metform-getresponse.git

2. Activate the plugin from the WordPress Admin > Plugins page.

3. Go to Settings > MetForm GetResponse to configure:

Your GetResponse API Key

Your Campaign ID

(Optional) Enable debug logging

⚙️ Usage

1. Create or edit a form in MetForm.
2. Ensure you have at least these fields:
mf-name
mf-email
3. Submit the form — the data will be pushed to GetResponse.

🐞 Debugging

Enable "Debug Logging" in the plugin settings.
Check logs in your WordPress debug.log file:
wp-content/debug.log

🔑 Requirements

WordPress 6.0+
PHP 7.4+
MetForm plugin installed and active
GetResponse API key

📜 License
This project is licensed under the MIT License.

Example debug log output:
[10-Sep-2025 20:24:49 UTC] MetForm hook fired! Form ID: {"action":"insert","id":"607","form_nonce":"9894218526","mf-name":"Test2","mf-email":"testtest@test.ru"} Entry ID: 607
[10-Sep-2025 20:24:49 UTC] Form submitted: name=Test2, email=testtest@test.ru
[10-Sep-2025 20:24:49 UTC] BYGI GetResponse response (202): 
