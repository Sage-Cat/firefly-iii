/*
 * boxes.js
 * Copyright (c) 2026 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

import Summary from "../../api/summary/index.js";
import { getVariables } from "../../store/get-variables.js";
import formatMoney from "../../util/format-money.js";
import { getCacheKey } from "../../support/get-cache-key.js";
import { cleanupCache } from "../../support/cleanup-cache.js";
import formatDate from "../../util/format-date.js";

let afterPromises = false;
let dashboardCurrencyDecimals = {};
let dashboardCurrencySymbols = {};

function dashboardMoney(amount, currencyCode) {
    return formatMoney(amount, currencyCode, dashboardCurrencyDecimals[currencyCode], dashboardCurrencySymbols[currencyCode]);
}

function dashboardFlowSubtitles(data, anonymous) {
    // Mask every flow equally; the presence of a subtitle must not reveal real zeroes.
    if (anonymous) {
        return [];
    }
    const currencies = new Map();
    for (const row of Object.values(data || {})) {
        if (!row || typeof row.key !== 'string' || typeof row.currency_code !== 'string') {
            continue;
        }
        const kind = row.key.startsWith('earned-in-') ? 'earned' : (row.key.startsWith('spent-in-') ? 'spent' : null);
        if (!kind || /^[+-]?(?:0+(?:\.0*)?|\.0+)(?:e[+-]?\d+)?$/i.test(String(row.monetary_value).trim())) {
            continue;
        }
        const amount = dashboardMoney(row.monetary_value, row.currency_code);
        if (!amount) {
            continue;
        }
        if (!currencies.has(row.currency_code)) {
            currencies.set(row.currency_code, {earned: [], spent: []});
        }
        currencies.get(row.currency_code)[kind].push(amount);
    }
    return Array.from(currencies.values(), row => [...row.earned, ...row.spent].join(' + '));
}

export default () => ({
    balanceBox: { amounts: [], subtitles: [] },
    billBox: { paid: [], unpaid: [] },
    leftBox: { left: [], perDay: [] },
    netBox: { net: [] },
    convertToPrimary: false,
    noMoneyLeft: false,
    loading: false,
    boxData: null,
    boxOptions: null,
    anonymous: false,
    eventListeners: {
        ["@convert-to-primary.window"](event) {
            this.convertToPrimary = event.detail;
            this.accountList = [];
            console.log("I heard that! (dashboard/boxes)");
            this.boxData = null;
            this.loadBoxes();
        },
    },

    getFreshData() {
        const start = new Date(window.store.get("start"));
        const end = new Date(window.store.get("end"));
        // TODO cache key is hard coded, problem?
        const boxesCacheKey = getCacheKey("ds_boxes_data", {
            convertToPrimary: this.convertToPrimary,
            start: start,
            end: end,
        });
        cleanupCache();

        //const cacheValid = window.store.get('cacheValid');
        let cachedData = window.store.get(boxesCacheKey);
        const cacheValid = false; // force refresh

        if (cacheValid && typeof cachedData !== "undefined") {
            this.boxData = cachedData;
            this.generateOptions(this.boxData);

            return;
        }

        // get stuff
        let getter = new Summary();
        getter
            .get(formatDate(start, "yyyy-MM-dd"), formatDate(end, "yyyy-MM-dd"), null)
            .then((response) => {
                this.boxData = response.data;
                window.store.set(boxesCacheKey, response.data);
                this.generateOptions(this.boxData);
            })
            .catch(() => {
                console.error("Request gave error.");
            });
    },
    generateOptions(data) {
        dashboardCurrencyDecimals = {};
        dashboardCurrencySymbols = {};
        Object.values(data || {}).forEach((row) => {
            if (row && typeof row.currency_code === "string" && Number.isInteger(row.currency_decimal_places)) {
                dashboardCurrencyDecimals[row.currency_code] = row.currency_decimal_places;
                dashboardCurrencySymbols[row.currency_code] = row.currency_symbol;
            }
        });
        this.balanceBox = { amounts: [], subtitles: [] };
        this.billBox = { paid: [], unpaid: [] };
        this.leftBox = { left: [], perDay: [] };
        this.netBox = { net: [] };
        // process new content:
        for (const i in data) {
            if (Object.hasOwn(data, i)) {
                const current = data[i];
                if (!Object.hasOwn(current, "key")) {
                    continue;
                }
                let key = current.key;
                if (key.startsWith("balance-in-")) {
                    this.balanceBox.amounts.push(
                        dashboardMoney(this.anonymous ? 0 : current.monetary_value, current.currency_code),
                    );
                    continue;
                }
                if (key.startsWith("bills-unpaid-in-")) {
                    this.billBox.unpaid.push(
                        dashboardMoney(this.anonymous ? 0 : current.monetary_value, current.currency_code),
                    );
                    continue;
                }
                if (key.startsWith("bills-paid-in-")) {
                    this.billBox.paid.push(
                        dashboardMoney(this.anonymous ? 0 : current.monetary_value, current.currency_code),
                    );
                    continue;
                }
                if (key.startsWith("left-to-spend-in-")) {
                    this.leftBox.left.push(
                        dashboardMoney(this.anonymous ? 0 : current.monetary_value, current.currency_code),
                    );
                    continue;
                }
                if (key.startsWith("left-per-day-to-spend-in-")) {
                    this.leftBox.perDay.push(
                        dashboardMoney(this.anonymous ? 0 : current.monetary_value, current.currency_code),
                    );
                    continue;
                }
                if (key.startsWith("net-worth-in-")) {
                    this.netBox.net.push(
                        dashboardMoney(this.anonymous ? 0 : current.monetary_value, current.currency_code),
                    );
                }
            }
        }

        this.noMoneyLeft = !Object.values(data || {}).some(
            (row) =>
                row &&
                typeof row.key === "string" &&
                row.key.startsWith("left-to-spend-in-") &&
                Number(row.monetary_value) > 0,
        );
        this.noMoneyLeft = this.anonymous ? true : this.noMoneyLeft;
        this.balanceBox.subtitles = dashboardFlowSubtitles(data, this.anonymous);
        this.loading = false;
    },
    loadBoxes() {
        if (true === this.loading) {
            return;
        }
        this.loading = true;
        if (null === this.boxData) {
            this.getFreshData();
            return;
        }
        this.generateOptions(this.boxData);
        this.loading = false;
    },

    // Getter
    init() {
        getVariables(["convert_to_primary", "anonymous"]).then((values) => {
            afterPromises = true;
            this.convertToPrimary = values.convert_to_primary;
            this.anonymous = values.anonymous;
            this.loadBoxes();
        });
        window.store.observe("end", () => {
            if (!afterPromises) {
                return;
            }
            // console.log('boxes observe end');
            this.boxData = null;
            this.loadBoxes();
        });
        window.store.observe("convert_to_primary", (newValue) => {
            if (!afterPromises) {
                return;
            }
            // console.log('boxes observe convertToPrimary');
            this.convertToPrimary = newValue;
            this.loadBoxes();
        });
        window.store.observe("anonymous", (newValue) => {
            if (!afterPromises) {
                return;
            }
            // console.log('boxes observe convertToPrimary');
            this.anonymous = newValue;
            this.loadBoxes();
        });
    },
});
