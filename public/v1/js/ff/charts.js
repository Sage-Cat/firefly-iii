/*
 * charts.js
 * Copyright (c) 2019 james@firefly-iii.org
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
var allCharts = {};


/*
 Make some colours:
 */
var colourSet = [
    [53, 124, 165],
    [0, 141, 76], // green
    [219, 139, 11],
    [202, 25, 90], // paars rood-ish #CA195A
    [85, 82, 153],
    [66, 133, 244],
    [219, 68, 55], // red #DB4437
    [244, 180, 0],
    [15, 157, 88],
    [171, 71, 188],
    [0, 172, 193],
    [255, 112, 67],
    [158, 157, 36],
    [92, 107, 192],
    [240, 98, 146],
    [0, 121, 107],
    [194, 24, 91]
];

var fillColors = [];

for (var i = 0; i < colourSet.length; i++) {
    fillColors.push("rgba(" + colourSet[i][0] + ", " + colourSet[i][1] + ", " + colourSet[i][2] + ", 0.5)");
}

Chart.defaults.global.legend.display = false;
Chart.defaults.global.animation.duration = 0;
Chart.defaults.global.responsive = true;
Chart.defaults.global.maintainAspectRatio = false;

/**
 *
 * @param data
 * @returns {{}}
 */
function colorizeData(data) {
    var newData = {};
    newData.datasets = [];

    for (var loop = 0; loop < data.count; loop++) {
        newData.labels = data.labels;
        var dataset = data.datasets[loop];
        dataset.fill = false;
        dataset.backgroundColor = dataset.borderColor = fillColors[loop];
        newData.datasets.push(dataset);
    }
    return newData;
}

/**
 * Function to draw a line chart:
 * @param URL
 * @param container
 */
function lineChart(URL, container) {
    "use strict";

    var colorData = true;
    var options = $.extend(true, {}, defaultChartOptions);
    var chartType = 'line';

    drawAChart(URL, container, chartType, options, colorData);
}

/**
 * Function to draw a line chart that doesn't start at ZERO.
 * @param URL
 * @param container
 */
function lineNoStartZeroChart(URL, container) {
    "use strict";

    var colorData = true;
    var options = $.extend(true, {}, defaultChartOptions);
    var chartType = 'line';
    options.scales.yAxes[0].ticks.beginAtZero = false;

    drawAChart(URL, container, chartType, options, colorData);
}

/**
 * Overrules the currency the line chart is drawn in.
 *
 * @param URL
 * @param container
 */
function otherCurrencyLineChart(URL, container, currencySymbol) {
    "use strict";

    var colorData = true;

    var newOpts = {
        scales: {
            xAxes: [
                {
                    gridLines: {
                        display: false
                    },
                    ticks: {
                        // break ticks when too long.
                        callback: function (value, index, values) {
                            return formatLabel(value, 20);
                        }
                    }
                }
            ],
            yAxes: [{
                display: true,
                //hello: 'fresh',
                ticks: {
                    callback: function (tickValue) {
                        "use strict";
                        // use first symbol or null:
                        return accounting.formatMoney(tickValue);

                    },
                    beginAtZero: true
                }
            }]
        },
    };

    //var options = $.extend(true, newOpts, defaultChartOptions);
    var options = $.extend(true, defaultChartOptions, newOpts);

    // console.log(options);
    var chartType = 'line';

    drawAChart(URL, container, chartType, options, colorData);
}

/**
 * Function to draw a chart with double Y Axes and stacked columns.
 *
 * @param URL
 * @param container
 */
function doubleYChart(URL, container) {
    "use strict";

    var colorData = true;
    var options = $.extend(true, {}, defaultChartOptions);
    options.scales.yAxes = [
        // y axis 0:
        {
            display: true,
            ticks: {
                callback: function (tickValue) {
                    "use strict";
                    return accounting.formatMoney(tickValue);

                },
                beginAtZero: true
            },
            position: "left",
            "id": "y-axis-0"
        },
        // and y axis 1:
        {
            display: true,
            ticks: {
                callback: function (tickValue) {
                    "use strict";
                    return accounting.formatMoney(tickValue);

                },
                beginAtZero: true
            },
            position: "right",
            "id": "y-axis-1"
        }

    ];
    options.stacked = true;
    options.scales.xAxes[0].stacked = true;

    var chartType = 'bar';

    drawAChart(URL, container, chartType, options, colorData);
}

/**
 * Function to draw a chart with double Y Axes and non stacked columns.
 *
 * @param URL
 * @param container
 */
function doubleYNonStackedChart(URL, container) {
    "use strict";

    var colorData = true;
    var options = $.extend(true, {}, defaultChartOptions);
    options.scales.yAxes = [
        // y axis 0:
        {
            display: true,
            ticks: {
                callback: function (tickValue) {
                    "use strict";
                    return accounting.formatMoney(tickValue);

                },
                beginAtZero: true
            },
            position: "left",
            "id": "y-axis-0"
        },
        // and y axis 1:
        {
            display: true,
            ticks: {
                callback: function (tickValue) {
                    "use strict";
                    return accounting.formatMoney(tickValue);

                },
                beginAtZero: true
            },
            position: "right",
            "id": "y-axis-1"
        }

    ];
    var chartType = 'bar';

    drawAChart(URL, container, chartType, options, colorData);
}


/**
 *
 * @param URL
 * @param container
 */
function columnChart(URL, container) {
    "use strict";
    var colorData = true;
    var options = $.extend(true, {}, defaultChartOptions);
    var chartType = 'bar';

    drawAChart(URL, container, chartType, options, colorData);
}


/**
 *
 * @param URL
 * @param container
 */
function columnChartCustomColours(URL, container) {
    "use strict";
    var colorData = false;
    var options = $.extend(true, {}, defaultChartOptions);
    var chartType = 'bar';

    drawAChart(URL, container, chartType, options, colorData);

}

/**
 *
 * @param URL
 * @param container
 */
function stackedColumnChart(URL, container) {
    "use strict";
    console.log('stackedColumnChart(' + URL + ', ' + container + ')');

    var colorData = true;
    var options = $.extend(true, {}, defaultChartOptions);

    options.stacked = true;
    options.scales.xAxes[0].stacked = true;
    options.scales.yAxes[0].stacked = true;

    var chartType = 'bar';

    drawAChart(URL, container, chartType, options, colorData);
}

/**
 *
 * @param URL
 * @param container
 */
function pieChart(URL, container) {
    "use strict";

    var colorData = false;
    var options = $.extend(true, {}, defaultPieOptions);
    var chartType = 'pie';

    drawAChart(URL, container, chartType, options, colorData);

}

/**
 *
 * @param URL
 * @param container
 */
function multiCurrencyPieChart(URL, container) {
    "use strict";

    var colorData = false;
    var options = $.extend(true, {}, pieOptionsWithCurrency);
    var chartType = 'pie';

    drawAChart(URL, container, chartType, options, colorData);

}

/**
 * @param URL
 * @param container
 * @param chartType
 * @param options
 * @param colorData
 * @param today
 */
function drawAChart(URL, container, chartType, options, colorData) {
    console.log('drawAChart(' + URL + ', ' + container + ')');
    var containerObj = document.getElementById(container);
    if (null === containerObj) {
        console.log('Return because NULL');
        return;
    }
    if (containerObj.length === 0) {
        console.log('Return because 0');
        return;
    }
    window.axios.get(URL).then(function (response) {
        //console.log('GET drawAChart('+URL+', '+container+')');
        containerObj.classList.remove('general-chart-error');
        var data = response.data;
        data = ledgerTopChartData(data, container, chartType);
        // if result is empty array, or the labels array is empty, show error.
        // console.log(URL);
        // console.log(data.length);
        // console.log(typeof data.labels);
        // console.log(data.labels.length);
        if (
            // is undefined
            typeof data === 'undefined' ||
            // is empty
            0 === data.length ||
            // isn't empty but contains no labels
            (typeof data === 'object' && typeof data.labels === 'object' && 0 === data.labels.length)
        ) {
            // console.log('NODATA drawAChart('+URL+', '+container+')');
            // console.log(data);
            // remove the chart container + parent
            var holder = document.getElementById(container).parentNode.parentNode;
            if (holder.classList.contains('card') || holder.classList.contains('card-body')) {
                // console.log('found holder');
                // find box-body:
                var boxBody;
                if (!holder.classList.contains('card-body')) {
                    // console.log('Look for card body', holder.querySelector('.card-body').length);
                    boxBody = holder.querySelector('.card-body');
                } else {
                    boxBody = holder;
                }
                // console.log('found box body', boxBody);
                // boxBody.innerHtml = '<p><em>'+noDataForChart+'</em></p>';
                boxBody.innerHTML = '<p><em>' + noDataForChart + '</em></p>';
                //boxBody.empty().append($('<p>').append($('<em>').text(noDataForChart)));
            }
            // console.log('return');
            return;
        }

        if (['categories-chart', 'expense-accounts-chart', 'revenue-accounts-chart'].indexOf(container) !== -1 && 'bar' === chartType) {
            options = ledgerBarChartOptions(options, data, container);
        }

        if (colorData) {
            data = colorizeData(data);
        }

        data = ledgerTopChartColors(data, container, chartType);

        if (allCharts.hasOwnProperty(container)) {
            allCharts[container].data.datasets = data.datasets;
            allCharts[container].data.labels = data.labels;
            allCharts[container].update();
        } else {
            // new chart!
            var ctx = document.getElementById(container).getContext("2d");
            var chartOpts = {
                type: chartType,
                data: data,
                options: options,
                lineAtIndex: [],
                annotation: {},
            };
            if (typeof drawVerticalLine !== 'undefined') {
                if (drawVerticalLine !== '') {
                    // draw line using annotation plugin.
                    chartOpts.options.annotation = {
                        annotations: [{
                            type: 'line',
                            id: 'a-line-1',
                            mode: 'vertical',
                            scaleID: 'x-axis-0',
                            value: drawVerticalLine,
                            borderColor: lineColor,
                            borderWidth: 1,
                            label: {
                                backgroundColor: 'rgba(0,0,0,0)',
                                fontFamily: "sans-serif",
                                fontSize: 12,
                                fontColor: lineTextColor,
                                position: "right",
                                xAdjust: -20,
                                yAdjust: -125,
                                enabled: true,
                                content: todayText
                            }
                        }]
                    };
                }
            }
            allCharts[container] = new Chart(ctx, chartOpts);
        }

    }).catch(function (reason) {
        // console.error('Add error because error with chart.', reason);
        document.getElementById(container).classList.add('general-chart-error');
    });
}

function ledgerTopChartData(data, container, chartType) {
    "use strict";
    var limits = {'categories-chart': 7, 'expense-accounts-chart': 3, 'revenue-accounts-chart': 3};
    var limit = Object.hasOwn(limits, container) ? limits[container] : 0;
    if (!limit || 'bar' !== chartType || typeof data === 'undefined' || Array.isArray(data) && data.length === 0
        || data && Array.isArray(data.labels) && data.labels.length === 0) {
        return data;
    }
    if (!data || !Array.isArray(data.labels) || !Array.isArray(data.datasets) || data.datasets.length !== 1
        || !Array.isArray(data.datasets[0].data) || data.labels.length !== data.datasets[0].data.length) {
        throw new Error('Dashboard rankings require one aligned UAH series.');
    }
    var dataset = data.datasets[0];
    var ranked = data.labels.map(function (label, index) {
        var value = dataset.data[index];
        var match = /^(?:([+-]?))(\d+)(?:\.(\d+))?$/.exec(String(value));
        if (!['string', 'number'].includes(typeof value) || !match || /[1-9]/.test((match[3] || '').slice(2))) {
            throw new Error('Dashboard rankings require exact monetary amounts.');
        }
        var cents = BigInt(match[2]) * 100n + BigInt((match[3] || '').padEnd(2, '0').slice(0, 2));
        if ('-' === match[1]) { cents = -cents; }
        return {index: index, label: label, cents: cents};
    }).filter(function (row) { return row.cents > 0n; }).sort(function (left, right) {
        return left.cents === right.cents ? left.index - right.index : left.cents > right.cents ? -1 : 1;
    }).slice(0, limit);
    var selected = Object.assign({}, dataset);
    ['data', 'backgroundColor', 'borderColor', 'hoverBackgroundColor', 'hoverBorderColor'].forEach(function (key) {
        if (Array.isArray(dataset[key]) && dataset[key].length === data.labels.length) {
            selected[key] = ranked.map(function (row) { return dataset[key][row.index]; });
        }
    });
    return Object.assign({}, data, {count: ranked.length ? 1 : 0,
        labels: ranked.map(function (row) { return row.label; }), datasets: ranked.length ? [selected] : []});
}

function ledgerCategoryColor(label) {
    "use strict";
    var name = String(label || 'Інше').normalize('NFKC').trim().toLowerCase().replace(/\s+/g, ' ');
    var families = [
        {pattern: /подат|бюджет|коміс|банків/, hue: 215, neutral: true},
        {pattern: /продукт|харч|їж|ресторан|кафе/, hue: 45},
        {pattern: /автомоб|пальн|таксі|транспорт|паркув|штраф|поштов/, hue: 205},
        {pattern: /цифров|підпис|програм|онлайн/, hue: 265},
        {pattern: /дім|житл|ремонт|комуналь|інтернет|зв[’']язок/, hue: 35},
        {pattern: /здоров|ліки|медиц|догляд/, hue: 285},
        {pattern: /освіт|наук|навчан/, hue: 55},
        {pattern: /подорож|дозвіл|відпоч|готел|розваг/, hue: 235},
        {pattern: /сімейн|благод|переказ.*люд/, hue: 30},
        {pattern: /покуп|одяг|взут/, hue: 280},
        {pattern: /повернен/, hue: 200},
        {pattern: /дохід|доход|зарплат|винагород|купон/, hue: 200},
        {pattern: /фоп|бізнес/, hue: 240},
        {pattern: /послуг/, hue: 275},
        {pattern: /^інш|уточнен|переказ|реквізит/, hue: 215, neutral: true}
    ];
    var family = families.find(function (group) { return group.pattern.test(name); });
    var hash = 2166136261;
    for (var character of name) {
        hash = Math.imul(hash ^ character.codePointAt(0), 16777619) >>> 0;
    }
    // Reserve red and green for the expense and income account charts.
    var offset = hash % 132;
    var fallbackHue = offset < 36 ? 25 + offset : 195 + offset - 36;
    var hue = family ? family.hue + hash % 11 - 5 : fallbackHue;
    var saturation = (family && family.neutral ? 22 : 62) + hash % 15;
    var lightness = 58 + (hash >>> 8) % 19;
    return {background: 'hsl(' + hue + ', ' + saturation + '%, ' + lightness + '%)',
        border: 'hsl(' + hue + ', ' + saturation + '%, ' + (lightness - 18) + '%)'};
}

function ledgerTopChartColors(data, container, chartType) {
    "use strict";
    var palette = {'expense-accounts-chart': ['#fca5a5', '#f87171'], 'revenue-accounts-chart': ['#86efac', '#4ade80']};
    var colors = 'bar' === chartType && Object.hasOwn(palette, container) ? palette[container] : null;
    if ('bar' === chartType && 'categories-chart' === container) {
        var categoryColors = data.labels.map(ledgerCategoryColor);
        data.datasets.forEach(function (dataset) {
            dataset.backgroundColor = categoryColors.map(function (color) { return color.background; });
            dataset.borderColor = categoryColors.map(function (color) { return color.border; });
            dataset.borderWidth = 1;
        });
    }
    if (colors) {
        data.datasets.forEach(function (dataset) {
            dataset.backgroundColor = colors[0];
            dataset.borderColor = colors[1];
            dataset.borderWidth = 1;
        });
    }
    return data;
}

function ledgerBarChartOptions(options, data, container) {
    "use strict";
    var datasets = data.datasets;
    datasets.forEach(function (dataset) {
        if ((dataset.currency_code && 'UAH' !== dataset.currency_code) || ['₴', 'грн'].indexOf(dataset.currency_symbol) === -1) {
            throw new Error('Ledger charts require UAH data and currency metadata.');
        }
        if (typeof dataset.currency_symbol !== 'string' || '' === dataset.currency_symbol) {
            throw new Error('Ledger charts require a dataset currency symbol.');
        }
    });
    options.scales.yAxes.forEach(function (axis, index) {
        var axisId = axis.id || 'y-axis-' + index;
        var first = datasets.find(function (dataset) {
            return dataset.yAxisID === axisId || String(dataset.yAxisID) === String(index);
        }) || datasets[0];
        var places = Number.isInteger(first.currency_decimal_places) ? first.currency_decimal_places : 2;
        axis.ticks.callback = function (value) {
            return accounting.formatMoney(anonymous ? 0 : value, first.currency_symbol, places);
        };
    });
    options.tooltips.callbacks.label = function (item, chartData) {
        var dataset = chartData.datasets[item.datasetIndex];
        var precision = Number.isInteger(dataset.currency_decimal_places) ? dataset.currency_decimal_places : 2;
        return dataset.label + ': ' + accounting.formatMoney(anonymous ? 0 : item.yLabel, dataset.currency_symbol, precision);
    };
    return options;
}
