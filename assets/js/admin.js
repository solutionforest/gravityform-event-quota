(function () {
	var root;
	var preserved = {};

	function ready(callback) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', callback);
		} else {
			callback();
		}
	}

	function config() {
		return window.gfeqAdmin || { fields: [], rules: [], defaultMessage: '(Full)', maxCombinations: 500 };
	}

	function ruleKey(choices) {
		return Object.keys(choices)
			.sort(function (left, right) {
				return parseInt(left, 10) - parseInt(right, 10);
			})
			.map(function (id) {
				return id + '=' + encodeURIComponent(choices[id]);
			})
			.join('&');
	}

	function selectedFields() {
		var selected = {};
		Array.prototype.forEach.call(document.querySelectorAll('input[name="_gform_setting_quota_fields[]"]:checked'), function (input) {
			selected[String(input.value)] = true;
		});

		return config().fields.filter(function (field) {
			return selected[String(field.id)];
		});
	}

	function combinations(fields) {
		var rows = [{}];
		for (var i = 0; i < fields.length; i++) {
			var next = [];
			var choices = fields[i].choices || [];
			if (!choices.length) {
				return [];
			}
			rows.forEach(function (row) {
				choices.forEach(function (choice) {
					var copy = {};
					Object.keys(row).forEach(function (key) {
						copy[key] = row[key];
					});
					copy[String(fields[i].id)] = choice.value;
					next.push(copy);
				});
			});
			rows = next;
		}
		return fields.length ? rows : [];
	}

	function savedRules() {
		var input = document.querySelector('input[name="_gform_setting_rules"]');
		if (input && input.value) {
			try {
				var parsed = JSON.parse(input.value);
				if (typeof parsed === 'string') {
					parsed = JSON.parse(parsed);
				}
				if (Array.isArray(parsed)) {
					return parsed;
				}
			} catch (error) {
				return config().rules || [];
			}
		}
		return config().rules || [];
	}

	function seedPreserved() {
		savedRules().forEach(function (rule) {
			if (!rule || !rule.choices) {
				return;
			}
			preserved[ruleKey(rule.choices)] = {
				limit: rule.limit === null || typeof rule.limit === 'undefined' ? '' : String(rule.limit),
				message: rule.full_message || ''
			};
		});
	}

	function rememberFromDom() {
		if (!root) {
			return;
		}
		Array.prototype.forEach.call(root.querySelectorAll('tr[data-key]'), function (row) {
			preserved[row.getAttribute('data-key')] = {
				limit: row.querySelector('.gfeq-limit').value,
				message: row.querySelector('.gfeq-message').value
			};
		});
	}

	function writeRules(rows) {
		var input = document.querySelector('input[name="_gform_setting_rules"]');
		if (!input) {
			return;
		}
		input.value = JSON.stringify(rows.map(function (row) {
			return {
				choices: row.choices,
				limit: row.limit,
				full_message: row.message
			};
		}));
	}

	function choiceText(field, value) {
		var match = (field.choices || []).filter(function (choice) {
			return choice.value === value;
		})[0];
		if (!match) {
			return value;
		}
		return match.text && match.text !== match.value ? match.text + ' (' + match.value + ')' : (match.text || match.value);
	}

	function render() {
		if (!root) {
			return;
		}
		rememberFromDom();
		var fields = selectedFields();
		root.textContent = '';

		if (!fields.length) {
			var empty = document.createElement('p');
			empty.className = 'gfeq-note';
			empty.textContent = 'Select the quota fields first.';
			root.appendChild(empty);
			writeRules([]);
			return;
		}

		var rows = combinations(fields);
		var max = config().maxCombinations || 500;
		if (rows.length > max) {
			var tooMany = document.createElement('p');
			tooMany.className = 'gfeq-note gfeq-note-warning';
			tooMany.textContent = rows.length + ' combinations is over the limit of ' + max + '. Use fewer fields or choices.';
			root.appendChild(tooMany);
			writeRules([]);
			return;
		}

		var summary = document.createElement('p');
		summary.className = 'gfeq-note';
		summary.textContent = fields.length + ' fields, ' + rows.length + ' combinations.';
		root.appendChild(summary);

		var table = document.createElement('table');
		table.className = 'gfeq-rules widefat';
		var head = document.createElement('thead');
		var headRow = document.createElement('tr');
		['Combination', 'Limit', 'Full message'].forEach(function (label) {
			var cell = document.createElement('th');
			cell.textContent = label;
			headRow.appendChild(cell);
		});
		head.appendChild(headRow);
		table.appendChild(head);

		var body = document.createElement('tbody');
		var payload = [];
		rows.forEach(function (choices) {
			var key = ruleKey(choices);
			var saved = preserved[key] || { limit: '', message: '' };
			payload.push({ choices: choices, limit: saved.limit, message: saved.message });

			var row = document.createElement('tr');
			row.setAttribute('data-key', key);

			var labelCell = document.createElement('td');
			labelCell.textContent = fields.map(function (field) {
				return field.label + ': ' + choiceText(field, choices[String(field.id)]);
			}).join(' + ');

			var limitCell = document.createElement('td');
			var limitInput = document.createElement('input');
			limitInput.type = 'number';
			limitInput.min = '0';
			limitInput.step = '1';
			limitInput.className = 'gfeq-limit';
			limitInput.value = saved.limit;
			limitInput.placeholder = 'Unlimited';
			limitCell.appendChild(limitInput);

			var messageCell = document.createElement('td');
			var messageInput = document.createElement('input');
			messageInput.type = 'text';
			messageInput.className = 'gfeq-message';
			messageInput.maxLength = 80;
			messageInput.value = saved.message;
			messageInput.placeholder = config().defaultMessage || '(Full)';
			messageCell.appendChild(messageInput);

			row.appendChild(labelCell);
			row.appendChild(limitCell);
			row.appendChild(messageCell);
			body.appendChild(row);
		});
		table.appendChild(body);
		root.appendChild(table);
		writeRules(payload);
	}

	ready(function () {
		root = document.getElementById('gfeq-rules-root');
		if (!root) {
			return;
		}
		seedPreserved();
		render();

		document.addEventListener('change', function (event) {
			if (event.target && event.target.name === '_gform_setting_quota_fields[]') {
				render();
			}
		});

		document.addEventListener('input', function (event) {
			if (!event.target || !event.target.classList) {
				return;
			}
			if (event.target.classList.contains('gfeq-limit') || event.target.classList.contains('gfeq-message')) {
				rememberFromDom();
				var rows = [];
				Array.prototype.forEach.call(root.querySelectorAll('tr[data-key]'), function (row) {
					var key = row.getAttribute('data-key');
					var saved = preserved[key];
					var choices = {};
					key.split('&').forEach(function (part) {
						var bits = part.split('=');
						choices[bits[0]] = decodeURIComponent(bits.slice(1).join('='));
					});
					rows.push({ choices: choices, limit: saved.limit, message: saved.message });
				});
				writeRules(rows);
			}
		});

		var form = document.getElementById('gform-settings');
		if (form) {
			form.addEventListener('submit', function () {
				rememberFromDom();
				render();
			});
		}
	});
}());
