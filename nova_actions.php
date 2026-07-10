<?php
/*
 * nova_actions.php
 *
 * NOVA's "hands": lets the assistant actually DO things after the user
 * confirms. Included by nova_chat.php (detection + instant info answers) and
 * nova_action.php (execution once the user taps Yes).
 *
 * Three kinds of intent:
 *   confirm  - state-changing actions (freeze card, lock online payments,
 *              create a savings goal...). NOVA asks "Are you sure?" and the
 *              app shows Yes/No buttons; only a Yes calls nova_action.php.
 *   navigate - "open the budget planner" -> the app navigates to the screen.
 *   info     - read-only answers (recent transactions, card status, virtual
 *              portfolio, budget status) answered instantly from MySQL.
 *
 * Detection is deterministic keyword matching (like novaDetectAccountIntent),
 * with the main keywords also covered in the app's four languages
 * (EN / SQ / DE / FR). The LLM is never involved in executing actions.
 *
 * Requires config.php ($conn). All feature helpers are pulled in lazily with
 * require_once so nothing clashes with nova_chat.php's own includes.
 */

require_once "card_db.php";

/* ---------------------------------------------------------------- */
/* Detection                                                          */
/* ---------------------------------------------------------------- */

/*
 * Returns null (no action — let the normal NOVA flow handle it) or:
 *   ["kind" => "confirm",  "action" => ..., "question" => ..., "params" => [...]]
 *   ["kind" => "navigate", "target" => routeName, "label" => friendlyName]
 *   ["kind" => "info",     "action" => ...]
 *   ["kind" => "reply",    "text" => ...]           // guidance message
 */
function novaDetectActionIntent($message) {
    $m = " " . mb_strtolower(trim($message)) . " ";

    $has = function (array $needles) use ($m) {
        foreach ($needles as $n) {
            if (mb_strpos($m, $n) !== false) return true;
        }
        return false;
    };

    /* ---------- card controls (confirm) ---------- */

    $mentionsCard        = $has(["card", "kart", "carte"]);
    $mentionsOnline      = $has(["online payment", "online payments", "online-zahlung", "pagesat online", "paiement en ligne", "paiements en ligne", " online "]);
    $mentionsContactless = $has(["contactless", "kontaktlos", "pa kontakt", "sans contact", "tap to pay"]);

    // Unfreeze BEFORE freeze ("unfreeze" contains "freeze").
    if ($mentionsCard && $has(["unfreeze", "un-freeze", "un freeze", "defrost", "unblock", "shkrij", "zhblloko", "entsperr", "auftauen", "débloque", "debloque", "dégèle", "degele"])) {
        return [
            "kind"     => "confirm",
            "action"   => "unfreeze_card",
            "question" => "Are you sure you want to unfreeze your card? Payments will work again.",
            "params"   => [],
        ];
    }
    if ($mentionsCard && $has(["freeze", "ngrij", "ngri", "einfrier", "sperr", "gèle", "gele ma carte", "bloque ma carte", "blloko kart", "block my card", "block the card"])) {
        return [
            "kind"     => "confirm",
            "action"   => "freeze_card",
            "question" => "Are you sure you want to freeze your card? All payments will be blocked until you unfreeze it.",
            "params"   => [],
        ];
    }

    if ($mentionsOnline) {
        // Unlock BEFORE lock ("unlock" contains "lock").
        if ($has(["unlock", "enable", "allow", "turn on", "activate", "lejo", "aktivizo", "erlaub", "aktivier", "autorise", "active "])) {
            return [
                "kind"     => "confirm",
                "action"   => "unlock_online",
                "question" => "Unlock online payments on your card?",
                "params"   => [],
            ];
        }
        if ($has(["lock", "disable", "block", "turn off", "stop", "blloko", "ndal", "çaktivizo", "sperr", "deaktivier", "bloque", "désactive", "desactive"])) {
            return [
                "kind"     => "confirm",
                "action"   => "lock_online",
                "question" => "Lock online payments on your card? Online purchases will be blocked.",
                "params"   => [],
            ];
        }
    }

    if ($mentionsContactless) {
        // Disable BEFORE enable ("deactivate" contains "activate").
        if ($has(["disable", "turn off", " off ", "deactivate", "stop", "blloko", "ndal", "çaktivizo", "fike", "deaktivier", "ausschalt", "désactive", "desactive", "coupe"])) {
            return [
                "kind"     => "confirm",
                "action"   => "disable_contactless",
                "question" => "Disable contactless payments on your card?",
                "params"   => [],
            ];
        }
        if ($has(["enable", "turn on", " on ", "activate", "allow", "lejo", "aktivizo", "ndez", "einschalt", "aktivier", "active", "autorise"])) {
            return [
                "kind"     => "confirm",
                "action"   => "enable_contactless",
                "question" => "Enable contactless payments on your card?",
                "params"   => [],
            ];
        }
    }

    /* ---------- create a savings goal (confirm) ---------- */

    $wantsGoal  = $has(["savings goal", "saving goal", "savings-goal", "objektiv kursimi", "qellim kursimi", "qëllim kursimi", "sparziel", "objectif d'épargne", "objectif d'epargne", "objectif epargne"]);
    $createVerb = $has(["create", "make", "start", "new", "set up", "setup", "add", "krijo", "fillo", "shto", "erstell", "anlegen", "neues", "crée", "cree", "nouveau", "nouvel"]);

    if ($wantsGoal && $createVerb) {
        $params = novaParseGoalParams($message);
        if ($params) {
            return [
                "kind"     => "confirm",
                "action"   => "create_goal",
                "question" => "Create the savings goal \"" . $params["name"] . "\" with a target of €" . number_format($params["amount"], 2) . "?",
                "params"   => $params,
            ];
        }
        return [
            "kind" => "reply",
            "text" => "Happy to set that up! 🎯 Tell me the name and target like this:\n\ncreate a savings goal \"Dubai Trip\" 500",
        ];
    }

    /* ---------- navigation ---------- */

    $navVerb = $has([
        // English — cover the natural ways people ask to be taken to a screen.
        "open ", "open up", "go to ", "goto ", "go into", "take me", "send me",
        "get me to", "bring me", "show me the", "navigate", "switch to", "jump to",
        "i want to go", "i wanna go", "i'd like to go", "let's go to", "lets go to",
        // Albanian
        "hap ", "shko te", "shko tek", "çoje", "coje", "dërgomë", "dergome", "më ço", "me co",
        // German
        "öffne", "geh zu", "zeig mir", "bring mich",
        // French
        "ouvre", "va à", "va a ", "emmène", "emmene", "amène", "amene",
    ]);

    // route name in the app => matching phrases
    $screens = [
        ["Budget Planner",    "the Budget Planner",        ["budget planner", "budget-planner", "budgets", "planifikuesi i buxhetit", "budgetplaner", "planificateur"]],
        ["Shared Savings",    "Shared Savings",            ["shared saving", "shared goal", "savings group", "saving group", "group savings", "save together", "kursime te perbashketa", "kursime të përbashkëta", "gemeinsames sparen", "épargne partagée", "epargne partagee"]],
        ["InvestLeaderboard", "the Investment Leaderboard",["leaderboard", "leader board", "top investors", "ranking", "renditja", "rangliste", "classement"]],
        ["Invest Simulator",  "the Invest Simulator",      ["invest", "investment", "investime", "simulator", "portfolio screen", "börse", "bourse"]],
        ["Analytics",         "Analytics",                 ["analytics", "statistics", "spending charts", "analitika", "statistikat", "statistik", "statistiques", "analyse"]],
        ["AI Coach",          "the AI Coach",              ["coach", "ai coach", "trajneri", "financial coach"]],
        ["Transfer",          "the Transfer screen",       ["transfer", "send money", "transfero", "dërgo para", "dergo para", "überweisung", "uberweisung", "geld senden", "virement", "envoyer de l'argent"]],
        ["Transactions",      "your Transactions",         ["transactions", "transaksionet", "transaktionen", "history"]],
        ["Savings",           "Savings",                   ["savings", "saving", "goals", "kursimet", "sparen", "épargne", "epargne"]],
        ["TopUp",             "Top Up",                    ["top up", "topup", "top-up", "mbush", "aufladen", "recharge"]],
        ["Cashback",          "Cashback",                  ["cashback", "cash back"]],
        ["Subscriptions",     "Subscriptions",             ["subscription", "abonim", "abo", "abonnement"]],
        ["Rewards",           "Rewards",                   ["rewards", "points", "shpërblimet", "shperblimet", "prämien", "praemien", "récompenses", "recompenses"]],
        ["Wordle Rewards",    "the Wordle game",           ["wordle"]],
        ["Apple Pay",         "Apple Pay",                 ["apple pay", "wallet"]],
        ["Card",              "your Card",                 ["card", "kartë", "karte", "carte"]],
        ["ATM Locations",     "ATM Locations",             ["atm", "bankomat"]],
        ["Notifications",     "Notifications",             ["notification", "njoftimet", "benachrichtigung"]],
        ["Settings",          "Settings",                  ["settings", "cilësimet", "cilesimet", "einstellungen", "paramètres", "parametres"]],
        ["My Character",      "My Character",              ["character", "avatar", "karakteri"]],
        ["Split The Bill",    "Split The Bill",            ["split"]],
        ["Round It Up",       "Round It Up",               ["round it up", "round up", "round-up"]],
        ["Profile",           "your Profile",              ["profile", "profili", "profil"]],
        ["MainTabs",          "Home",                      ["home", "ballina", "startseite", "accueil"]],
    ];

    if ($navVerb) {
        foreach ($screens as $s) {
            foreach ($s[2] as $alias) {
                if (mb_strpos($m, $alias) !== false) {
                    return ["kind" => "navigate", "target" => $s[0], "label" => $s[1]];
                }
            }
        }
    }

    /* ---------- read-only info (instant answers) ---------- */

    if ($has(["transaction", "transaksion", "transaktion", "recent payments", "last payments", "latest payments"])) {
        return ["kind" => "info", "action" => "show_transactions"];
    }

    if ($mentionsCard && $has(["status", "frozen", "is my card", "e ngrirë", "e ngrire", "gesperrt", "statut", "gelée", "gelee"])) {
        return ["kind" => "info", "action" => "card_status"];
    }

    if ($has(["portfolio", "my investments", "investimet e mia", "mein portfolio", "mon portefeuille"])) {
        return ["kind" => "info", "action" => "portfolio"];
    }

    if ($has(["budget status", "budget left", "remaining budget", "my budget", "my budgets", "budget summary", "how are my budgets", "buxheti im", "mein budget", "mon budget"])) {
        return ["kind" => "info", "action" => "budget_status"];
    }

    return null;
}

/* Parse the goal name + amount out of e.g. 'create a savings goal "Dubai" 500'. */
function novaParseGoalParams($message) {
    $name = null;

    // 1) A quoted name is the most reliable.
    if (preg_match('/["“”\'‘’]([^"“”\'‘’]{2,60})["“”\'‘’]/u', $message, $mt)) {
        $name = trim($mt[1]);
    }
    // 2) Otherwise "called X" / "named X" / "for X".
    elseif (preg_match('/(?:called|named|for)\s+([[:alpha:]][[:alpha:]\d\s]{1,40}?)(?=\s+(?:for|with|of|at|target)\b|\s*[\d€]|$)/iu', $message, $mt)) {
        $name = trim($mt[1]);
    }

    // Amount: the first standalone number (500, 1.500, 1,500.50, €500 ...).
    $amount = null;
    if (preg_match('/(\d[\d.,]*)/', $message, $mt)) {
        $raw = $mt[1];
        // Normalize 1.500,50 / 1,500.50 / 500,5 -> float.
        $raw = str_replace(" ", "", $raw);
        if (preg_match('/,\d{1,2}$/', $raw)) {
            $raw = str_replace(".", "", $raw);
            $raw = str_replace(",", ".", $raw);
        } else {
            $raw = str_replace(",", "", $raw);
        }
        $amount = round((float) $raw, 2);
    }

    if ($name && $amount && $amount > 0) {
        return ["name" => mb_substr($name, 0, 60), "amount" => $amount];
    }
    return null;
}

/* ---------------------------------------------------------------- */
/* Response building (used by nova_chat.php)                         */
/* ---------------------------------------------------------------- */

/* Turn a detected intent into the JSON payload nova_chat.php echoes. */
function novaActionResponse($conn, $user_id, $intent) {
    switch ($intent["kind"]) {
        case "confirm":
            return [
                "status" => "success",
                "reply"  => $intent["question"],
                "source" => "action",
                "action" => [
                    "type"    => $intent["action"],
                    "confirm" => true,
                    "params"  => $intent["params"],
                ],
            ];

        case "navigate":
            return [
                "status" => "success",
                "reply"  => "Sure — taking you to " . $intent["label"] . "… 🚀",
                "source" => "action",
                "action" => [
                    "type"   => "navigate",
                    "target" => $intent["target"],
                    "label"  => $intent["label"],
                ],
            ];

        case "info":
            $reply = novaActionAnswer($conn, $user_id, $intent["action"]);
            return [
                "status" => "success",
                "reply"  => $reply !== null ? $reply : "I couldn't load that right now — please try again in a moment.",
                "source" => "action",
            ];

        case "reply":
        default:
            return [
                "status" => "success",
                "reply"  => $intent["text"] ?? "Sorry, I didn't catch that.",
                "source" => "action",
            ];
    }
}

/* ---------------------------------------------------------------- */
/* Read-only info answers                                             */
/* ---------------------------------------------------------------- */

function novaActionAnswer($conn, $user_id, $action) {
    if (!$user_id) return null;

    try {
        switch ($action) {
            case "show_transactions": {
                $stmt = $conn->prepare("SELECT id FROM accounts WHERE user_id = ? LIMIT 1");
                $stmt->execute([$user_id]);
                $acc = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$acc) return null;
                $accId = (int) $acc["id"];

                $stmt = $conn->prepare("
                    SELECT t.sender_account, t.amount, t.description, t.created_at,
                           su.name AS sn, su.surname AS ss,
                           ru.name AS rn, ru.surname AS rs
                    FROM transactions t
                    JOIN accounts sa ON t.sender_account = sa.id
                    JOIN users su ON sa.user_id = su.id
                    JOIN accounts ra ON t.receiver_account = ra.id
                    JOIN users ru ON ra.user_id = ru.id
                    WHERE t.sender_account = ? OR t.receiver_account = ?
                    ORDER BY t.created_at DESC, t.id DESC
                    LIMIT 5
                ");
                $stmt->execute([$accId, $accId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) {
                    return "You don't have any transactions yet.";
                }

                $lines = ["🧾 Your latest transactions:"];
                foreach ($rows as $t) {
                    $out   = ((int) $t["sender_account"] === $accId);
                    $other = $out ? trim($t["rn"] . " " . $t["rs"]) : trim($t["sn"] . " " . $t["ss"]);
                    if ($other === "DS Banking House") {
                        $other = trim((string) $t["description"]) !== "" ? $t["description"] : "DS Banking";
                    }
                    $when    = date("j M", strtotime($t["created_at"]));
                    $amt     = number_format((float) $t["amount"], 2);
                    $lines[] = ($out ? "↗ −€" : "↙ +€") . $amt . " · " . $other . " · " . $when;
                }
                return implode("\n", $lines);
            }

            case "card_status": {
                $frozen   = isCardFrozen($conn, $user_id);
                $controls = getCardControls($conn, $user_id);
                return "💳 Card status:\n"
                    . ($frozen ? "• Card: ❄️ frozen (payments blocked)\n" : "• Card: ✅ active\n")
                    . ($controls["online_locked"] ? "• Online payments: 🔒 locked\n" : "• Online payments: ✅ allowed\n")
                    . ($controls["contactless_enabled"] ? "• Contactless: ✅ on" : "• Contactless: 🚫 off");
            }

            case "portfolio": {
                require_once "invest_db.php";
                ensureInvestSchema($conn);
                $wallet = getOrCreateInvestWallet($conn, (int) $user_id);

                $assets = investAssets();
                $now    = time();
                $value  = $wallet["cash"];
                $invested = 0.0;
                $stmt = $conn->prepare("SELECT asset, units, invested FROM invest_holdings WHERE user_id = ? AND units > 0");
                $stmt->execute([$user_id]);
                while ($h = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $key = $h["asset"];
                    if (!isset($assets[$key])) continue;
                    $value    += (float) $h["units"] * investCurrentPrice($conn, $assets[$key], $now);
                    $invested += (float) $h["invested"];
                }
                $pl = round($value - $wallet["cash"] - $invested, 2);

                $reply = "📈 Your virtual portfolio is worth €" . number_format($value, 2)
                       . " (free cash €" . number_format($wallet["cash"], 2)
                       . ", invested €" . number_format($invested, 2) . ").";
                if ($invested > 0) {
                    $plPct = round($pl / $invested * 100, 2);
                    $reply .= " P/L: " . ($pl >= 0 ? "+" : "−") . "€" . number_format(abs($pl), 2)
                            . " (" . ($plPct >= 0 ? "+" : "") . $plPct . "%).";
                }
                return $reply;
            }

            case "budget_status": {
                require_once "feature_db.php";
                require_once "analytics_db.php";
                require_once "budgets_db.php";
                ensureFeatureSchema($conn);
                $o = budgetOverview($conn, (int) $user_id, date("Y-m"));

                if (count($o["budgets"]) === 0) {
                    return "You haven't set any budgets for " . $o["month_label"]
                         . " yet. Open the Budget Planner to create your first one! 🎯";
                }

                $t = $o["totals"];
                $lines = ["🧮 " . $o["month_label"] . " budgets: spent €" . number_format($t["spent"], 2)
                        . " of €" . number_format($t["limit"], 2) . " (" . round($t["pct"]) . "%)."];
                foreach ($o["budgets"] as $b) {
                    if ($b["pct"] >= 100) {
                        $lines[] = "🔴 " . $b["label"] . ": over budget by €" . number_format($b["spent"] - $b["limit_amount"], 2);
                    } elseif ($b["pct"] >= 80) {
                        $lines[] = "🟡 " . $b["label"] . ": " . round($b["pct"]) . "% used — €" . number_format($b["remaining"], 2) . " left";
                    }
                }
                if (count($lines) === 1) {
                    $lines[] = "✅ All categories are on track. Nice!";
                }
                return implode("\n", $lines);
            }
        }
    } catch (Exception $e) {
        error_log("NOVA action answer error: " . $e->getMessage());
    }
    return null;
}

/* ---------------------------------------------------------------- */
/* Execution of confirmed actions (used by nova_action.php)           */
/* ---------------------------------------------------------------- */

/*
 * Execute a confirmed action. Returns the reply NOVA shows on success;
 * throws on failure.
 */
function novaExecuteAction($conn, $user_id, $action, $params) {
    require_once "notifications_db.php";

    switch ($action) {
        case "freeze_card":
            if (isCardFrozen($conn, $user_id)) {
                return "Your card is already frozen. ❄️";
            }
            setCardFrozen($conn, $user_id, true);
            try {
                addNotification($conn, $user_id, "card", "Card frozen",
                    "Your card has been frozen. Payments are now blocked until you unfreeze it.");
            } catch (Exception $e) {}
            return "❄️ Done — your card is frozen. All payments are blocked until you unfreeze it.";

        case "unfreeze_card":
            if (!isCardFrozen($conn, $user_id)) {
                return "Your card isn't frozen — you're good to go. ✅";
            }
            setCardFrozen($conn, $user_id, false);
            try {
                addNotification($conn, $user_id, "card", "Card unfrozen",
                    "Your card is active again. You can make payments as usual.");
            } catch (Exception $e) {}
            return "✅ Done — your card is active again. You can make payments as usual.";

        case "lock_online":
            setCardControl($conn, $user_id, "online_locked", true);
            try {
                addNotification($conn, $user_id, "card", "Online payments locked",
                    "Online payments on your card are now locked.");
            } catch (Exception $e) {}
            return "🔒 Done — online payments are locked on your card.";

        case "unlock_online":
            setCardControl($conn, $user_id, "online_locked", false);
            try {
                addNotification($conn, $user_id, "card", "Online payments unlocked",
                    "Online payments on your card are allowed again.");
            } catch (Exception $e) {}
            return "🔓 Done — online payments are allowed again.";

        case "disable_contactless":
            setCardControl($conn, $user_id, "contactless_enabled", false);
            try {
                addNotification($conn, $user_id, "card", "Contactless disabled",
                    "Contactless payments on your card are now off.");
            } catch (Exception $e) {}
            return "🚫 Done — contactless payments are off.";

        case "enable_contactless":
            setCardControl($conn, $user_id, "contactless_enabled", true);
            try {
                addNotification($conn, $user_id, "card", "Contactless enabled",
                    "Contactless payments on your card are on again.");
            } catch (Exception $e) {}
            return "✅ Done — contactless payments are on.";

        case "create_goal": {
            require_once "feature_db.php";
            require_once "goals_db.php";

            $name   = trim($params["name"] ?? "");
            $amount = round(floatval($params["amount"] ?? 0), 2);
            if ($name === "" || $amount <= 0) {
                throw new Exception("Missing goal name or amount");
            }
            if (mb_strlen($name) > 120) {
                $name = mb_substr($name, 0, 120);
            }

            ensureGoalsSchema($conn);
            $stmt = $conn->prepare("
                INSERT INTO savings_goals (user_id, name, description, icon, target_amount, saved_amount, status)
                VALUES (?, ?, NULL, 'target', ?, 0.00, 'active')
            ");
            $stmt->execute([$user_id, $name, $amount]);

            return "🎯 Savings goal \"" . $name . "\" created with a target of €" . number_format($amount, 2)
                 . ". Add money to it any time on the Savings screen!";
        }
    }

    throw new Exception("Unknown action");
}
