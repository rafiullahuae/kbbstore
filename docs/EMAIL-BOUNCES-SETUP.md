# Bounced emails: setting it up (about 5 minutes)

**Where:** Admin → Growth & Marketing → **Bounces & unsubscribes** → **Setup**.
The same steps are printed on that screen, with your real addresses filled in
and a Copy button.

## What it does

- When a marketing email bounces, Google sends a "Delivery Status Notification"
  from *mailer-daemon*. A Gmail filter puts those into a label called
  **KBB Bounces**, and they never reach your inbox.
- Every 5 minutes the shop reads **only that label**, records each bounce and
  moves the message to **KBB Bounces/Processed**. It never opens your inbox.
- An address that does not exist is taken off every marketing list at once
  and shown under **Bounced**. A full mailbox or a busy server is counted under
  **Watching**; three times in 30 days and it moves to Bounced as well.
- **Restore** puts an address back on the list (it asks "Are you sure?" first).
- Order confirmations are **never** blocked by any of this.

## Step 1: the app password

If Store → Mail already sends with **Google Workspace (Gmail SMTP)** and an app
password, you have nothing to do here: the same app password reads bounces.

Otherwise, signed in as the account that sends (e.g. info@kbeautybliss.com):
myaccount.google.com → **Security** → **2-Step Verification** (turn it on) →
**App passwords** → name it "KBB bounces" → **Create** → copy the 16 letters.

Make sure IMAP is on: Gmail → ⚙ → **See all settings** → **Forwarding and
POP/IMAP** → **Enable IMAP** → **Save changes**. If that option is missing,
open admin.google.com → Apps → Google Workspace → Gmail → **End User Access**
and turn **POP and IMAP access** on.

## Step 2: the Gmail filter

1. In Gmail, click the sliders icon at the right end of the search box.
2. In **Has the words**, paste the line shown on the Setup screen. For
   info@kbeautybliss.com it is:

   `{from:mailer-daemon from:postmaster to:info+unsubscribe@kbeautybliss.com}`

3. Click **Create filter**, then tick:
   - **Skip the Inbox (Archive it)**
   - **Mark as read**
   - **Apply the label** → *New label…* → `KBB Bounces`
   - **Never send it to Spam**
   - **Also apply filter to matching conversations** (this tidies the old ones)
4. Click **Create filter**.

This filter only catches mail sent by mail systems (mailer-daemon and
postmaster) and the shop's unsubscribe address. It never catches a customer's
email.

## Step 3: switch it on

On the Setup screen:

1. **Read bounces from Gmail every 5 minutes**: on.
2. **Remove bounced addresses from every marketing list automatically**: on
   (this is the default).
3. Leave **Gmail account** and **App password** blank to use the ones from
   Store → Mail. Fill them in only if bounces go to a different mailbox.
4. Press **Save**, then press **Test connection**. You should see *"Connected …
   The label "KBB Bounces" has N reports waiting."*
5. Press **Read now** once to file the reports already waiting.

The reading runs from the same Cloudways cron line as the campaigns
(`* * * * * cd <app> && php artisan schedule:run`). If campaigns already send
on their own, nothing more is needed.

## The sending pace (already on)

On Google Workspace, marketing emails go out one at a time, every **8 to 12
seconds**. That is at most **6 a minute** and **1,500 a day**, which leaves 500
of Google's 2,000-a-day allowance for order emails. If Google answers "slow
down", sending pauses on its own (5 minutes, then 10, then 20, and so on) and
then carries on. You can change it under:

- Bounces & unsubscribes → Setup → **Sending pace** (seconds between emails)
- Marketing Emails → Campaigns → **Sending limits** (per minute and per day)

If you had already saved a daily limit of 2,000 there, it stays at 2,000 until
you change it. Only the default moved.

## Deliverability

Bounces & unsubscribes → **Deliverability** → **Check now** looks up SPF, DKIM
and DMARC for your sending domain. Where a record is missing, it shows the
exact record to add at your DNS host. Gmail and Yahoo require all three from
bulk senders.
