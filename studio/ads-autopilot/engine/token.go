package main

import (
	"context"
	"fmt"
	"math"
	"strings"
	"time"

	"kaa/meta"
)

// token.go — the two ways to connect Meta (the owner, 20 Nov 2026: "my meta account is not verified. the system should
// work in any case"). docs/META-SETUP.md is the click-by-click guide for both.
//
//   system_user  a system-user token from Business Settings → Users → System users. Does not expire by time.
//   user_token   his own user token from his own Meta app (Graph API Explorer). The engine exchanges it for a
//                long-lived one (~60 days) with the app id + secret, reads its expiry with /debug_token, shows it,
//                warns 7 days ahead and, from 10 days before expiry, tries to extend it once a day. If it lapses,
//                Meta's own rules and the account spending limit keep protecting the ads; the app just stops
//                changing anything until a new token is pasted.
//
// Neither path needs Business Verification: the owner has a role on his own app, so Standard access to
// ads_management / ads_read is enough (META-FACTS §12).

const (
	AuthSystemUser = "system_user"
	AuthUserToken  = "user_token"
	warnDays       = 7
	refreshDays    = 10
)

var neededScopes = []string{"ads_management", "ads_read"}

func (a *App) appToken() string {
	a.mu.RLock()
	id := a.cfg.MetaAppID
	a.mu.RUnlock()
	sec, _ := a.sec.Get("meta_app_secret")
	if id == "" || sec == "" {
		return ""
	}
	return id + "|" + sec
}

// inspectToken reads type / expiry / scopes of the saved token into the config.
func (a *App) inspectToken(ctx context.Context) error {
	tok, _ := a.sec.Get("meta_token")
	if tok == "" {
		return nil
	}
	as := a.appToken()
	if as == "" {
		as = tok
	}
	a.mu.RLock()
	mc := a.meta
	a.mu.RUnlock()
	ti, err := mc.DebugToken(ctx, tok, as)
	if err != nil {
		return err
	}
	a.mu.Lock()
	a.cfg.TokenType, a.cfg.TokenExpires, a.cfg.TokenScopes, a.cfg.TokenValid = ti.Type, ti.ExpiresAt, ti.Scopes, ti.Valid
	cfg := a.cfg
	a.mu.Unlock()
	_ = a.st.Save("config", cfg)
	return nil
}

// saveMetaToken: a system-user token is saved as it is; a user token is exchanged for a long-lived one first when
// the app id and secret are there.
func (a *App) saveMetaToken(ctx context.Context, auth, tok string) error {
	tok = strings.TrimSpace(tok)
	if auth == AuthUserToken {
		a.mu.RLock()
		id, mc := a.cfg.MetaAppID, a.meta
		a.mu.RUnlock()
		sec, _ := a.sec.Get("meta_app_secret")
		if id != "" && sec != "" {
			long, life, err := mc.ExchangeToken(ctx, id, sec, tok)
			if err != nil {
				return fmt.Errorf("Meta would not turn this token into a 60-day one: %v. Make a fresh token in Graph API Explorer and paste it within the hour", err)
			}
			a.st.Append("config", fmt.Sprintf("Meta user token exchanged for a long-lived one (%d days)", int(life.Hours()/24)), nil)
			tok = long
		}
	}
	return a.sec.Set("meta_token", tok)
}

// tokenStatus: the human side of the token for /api/connections.
func (a *App) tokenStatus(now time.Time) (days int, warning string) {
	a.mu.RLock()
	c := a.cfg
	a.mu.RUnlock()
	if c.TokenExpires.IsZero() {
		if c.TokenType == "USER" || c.MetaAuth == AuthUserToken && c.TokenType == "" {
			return -1, ""
		}
		return -1, ""
	}
	left := c.TokenExpires.Sub(now)
	days = int(math.Floor(left.Hours() / 24))
	switch {
	case left <= 0:
		warning = "The Meta token expired on " + c.TokenExpires.Format("2 Jan 2006") + ". The app cannot change anything until a new one is pasted in Settings → Connections → Meta. Meta's own rules and the account spending limit keep protecting the ads meanwhile."
	case days < warnDays:
		warning = fmt.Sprintf("The Meta token expires on %s (in %d days). Paste a new one in Settings → Connections → Meta (see the setup guide), or switch to a system-user token that does not expire.", c.TokenExpires.Format("2 Jan 2006"), days)
	}
	var missing []string
	for _, s := range neededScopes {
		found := false
		for _, x := range c.TokenScopes {
			if x == s {
				found = true
			}
		}
		if !found && len(c.TokenScopes) > 0 {
			missing = append(missing, s)
		}
	}
	if len(missing) > 0 && warning == "" {
		warning = "The Meta token lacks the permission(s) " + strings.Join(missing, ", ") + ": make it again with those ticked."
	}
	return days, warning
}

// maintainToken: once a day. Warn 7 days ahead; from 10 days ahead try to extend a user token (Meta may hand back the
// same expiry: then the warning stands and the owner pastes a fresh token).
func (a *App) maintainToken(ctx context.Context) {
	if err := a.inspectToken(ctx); err != nil {
		if me, ok := meta.AsError(err); ok && me.TokenInvalid() {
			a.alertOnce("Meta refused the saved token (expired or revoked). Paste a new one in Settings → Connections → Meta. Meta's own rules and spending limit keep protecting the ads meanwhile.")
		}
		return
	}
	now := a.now()
	a.mu.RLock()
	c := a.cfg
	mc := a.meta
	a.mu.RUnlock()
	if c.MetaAuth == AuthUserToken && !c.TokenExpires.IsZero() && c.TokenExpires.After(now) && c.TokenExpires.Sub(now) < refreshDays*24*time.Hour {
		sec, _ := a.sec.Get("meta_app_secret")
		tok, _ := a.sec.Get("meta_token")
		if c.MetaAppID != "" && sec != "" {
			if long, _, err := mc.ExchangeToken(ctx, c.MetaAppID, sec, tok); err == nil && long != tok {
				old := c.TokenExpires
				_ = a.sec.Set("meta_token", long)
				if a.inspectToken(ctx) == nil {
					a.mu.RLock()
					nu := a.cfg.TokenExpires
					a.mu.RUnlock()
					if nu.After(old) {
						a.st.Append("config", "Meta token extended to "+nu.Format("2 Jan 2006"), nil)
					} else {
						a.st.Append("config", "Meta handed back a token with the same expiry ("+nu.Format("2 Jan 2006")+")", nil)
					}
				}
			}
		}
	}
	if _, w := a.tokenStatus(now); w != "" {
		a.alertOnce(w)
	}
}
