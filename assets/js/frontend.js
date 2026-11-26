(function ($) {
	var timers = {};
	var applying = false;

	function forms() {
		return window.gfeqForms || {};
	}

	function isHidden(formId, fieldId) {
		var field = document.getElementById('field_' + formId + '_' + fieldId);
		if (!field) {
			return false;
		}
		// Page breaks hide an ancestor, not the field. Only conditional logic hides the field itself.
		return field.classList.contains('gfield_visibility_hidden') || field.style.display === 'none';
	}

	function choiceNodes(formId, field) {
		if (field.inputType === 'select') {
			var select = document.getElementById('input_' + formId + '_' + field.id);
			if (!select || select.tagName !== 'SELECT') {
				return [];
			}
			return Array.prototype.filter.call(select.options, function (option) {
				return option.value !== '';
			});
		}

		var container = document.getElementById('field_' + formId + '_' + field.id);
		if (!container) {
			return [];
		}
		return Array.prototype.filter.call(container.querySelectorAll('input.gfield-choice-input'), function (input) {
			if (input.id && input.id.indexOf('select_all') !== -1) {
				return false;
			}
			return input.type === 'checkbox' || input.type === 'radio';
		});
	}

	function labelFor(input) {
		if (!input.id) {
			return null;
		}
		var labels = document.getElementsByTagName('label');
		for (var i = 0; i < labels.length; i++) {
			if (labels[i].htmlFor === input.id) {
				return labels[i];
			}
		}
		return null;
	}

	function labelText(label) {
		var text = '';
		for (var i = 0; i < label.childNodes.length; i++) {
			if (label.childNodes[i].nodeType === 3) {
				text += label.childNodes[i].nodeValue;
			}
		}
		return text.trim();
	}

	function originalText(node) {
		var stored = node.getAttribute('data-gfeq-label');
		if (stored !== null) {
			return stored;
		}
		var text = node.tagName === 'OPTION' ? node.text : labelText(labelFor(node) || node);
		node.setAttribute('data-gfeq-label', text);
		return text;
	}

	function setText(node, text) {
		if (node.tagName === 'OPTION') {
			node.text = text;
			return;
		}
		var label = labelFor(node);
		if (!label) {
			return;
		}
		var written = false;
		for (var i = 0; i < label.childNodes.length; i++) {
			var child = label.childNodes[i];
			if (child.nodeType === 3 && child.nodeValue.trim() !== '') {
				child.nodeValue = written ? '' : text;
				written = true;
			}
		}
		if (!written) {
			label.appendChild(document.createTextNode(text));
		}
	}

	function stateFor(states, value) {
		if (states && states[value]) {
			return states[value];
		}
		var pipe = value.indexOf('|');
		if (pipe > 0 && states && states[value.substring(0, pipe)]) {
			return states[value.substring(0, pipe)];
		}
		return { full: false, message: '' };
	}

	function readSelections(formId, config) {
		var selections = {};
		config.fields.forEach(function (field) {
			var values = [];
			if (!isHidden(formId, field.id)) {
				choiceNodes(formId, field).forEach(function (node) {
					var selected = node.tagName === 'OPTION' ? node.selected : node.checked;
					if (selected && node.value) {
						values.push(node.value);
					}
				});
			}
			selections[field.id] = values;
		});
		return selections;
	}

	function readQuantity(formId, fieldId) {
		if (!fieldId || isHidden(formId, fieldId)) {
			return '';
		}
		var input = document.getElementById('input_' + formId + '_' + fieldId);
		if (!input) {
			var form = document.getElementById('gform_' + formId);
			input = form ? form.querySelector('[name="input_' + fieldId + '"]') : null;
		}
		return input ? input.value : '';
	}

	function applyStates(formId, config, options) {
		var changed = false;
		applying = true;
		try {
			config.fields.forEach(function (field) {
				var states = options[field.id] || options[String(field.id)] || {};
				choiceNodes(formId, field).forEach(function (node) {
					var state = stateFor(states, node.value);
					var full = !!state.full;
					var original = originalText(node);
					setText(node, full && state.message ? original + state.message : original);
					node.disabled = full;

					var choice = node.closest ? node.closest('.gchoice') : null;
					if (choice) {
						choice.classList.toggle('gfeq-full', full);
					}

					if (!full) {
						return;
					}
					if (node.tagName === 'OPTION' && node.selected) {
						node.selected = false;
						if (node.parentNode) {
							node.parentNode.value = '';
							$(node.parentNode).trigger('change');
						}
						changed = true;
					} else if (node.checked) {
						node.checked = false;
						$(node).trigger('change');
						changed = true;
					}
				});
			});
			return changed;
		} finally {
			applying = false;
		}
	}

	function refresh(formId) {
		var config = forms()[formId];
		if (!config || !document.getElementById('gform_' + formId)) {
			return;
		}
		config._serial = (config._serial || 0) + 1;
		var serial = config._serial;

		$.post(config.ajaxUrl, {
			action: 'gfeq_check_quota',
			nonce: config.nonce,
			form_id: formId,
			quantity: readQuantity(formId, config.quantityFieldId),
			selections: readSelections(formId, config)
		}).done(function (response) {
			if (!forms()[formId] || forms()[formId]._serial !== serial) {
				return;
			}
			if (!response || !response.success || !response.data) {
				return;
			}
			var changed = applyStates(formId, config, response.data.options || {});
			if (changed && (config._chain || 0) < 4) {
				config._chain = (config._chain || 0) + 1;
				refresh(formId);
			} else {
				config._chain = 0;
			}
		});
	}

	function schedule(formId) {
		var config = forms()[formId];
		if (config) {
			config._chain = 0;
		}
		window.clearTimeout(timers[formId]);
		timers[formId] = window.setTimeout(function () {
			refresh(formId);
		}, 150);
	}

	function isWatched(formId, target) {
		var config = forms()[formId];
		if (!config || !target || !target.closest) {
			return false;
		}
		var field = target.closest('.gfield');
		if (!field || !field.id) {
			return false;
		}
		var prefix = 'field_' + formId + '_';
		if (field.id.indexOf(prefix) !== 0) {
			return false;
		}
		var fieldId = field.id.substring(prefix.length);
		if (String(config.quantityFieldId) === String(fieldId)) {
			return true;
		}
		return config.fields.some(function (fieldConfig) {
			return String(fieldConfig.id) === String(fieldId);
		});
	}

	$(document).on('change input', 'form', function (event) {
		if (applying) {
			return;
		}
		var form = this;
		if (!form.id || form.id.indexOf('gform_') !== 0) {
			return;
		}
		var formId = form.id.substring('gform_'.length);
		if (!forms()[formId] || !isWatched(formId, event.target)) {
			return;
		}
		schedule(formId);
	});

	$(document).on('gform_post_render gform_post_conditional_logic', function (event, formId) {
		if (forms()[formId]) {
			schedule(formId);
		}
	});

	$(function () {
		Object.keys(forms()).forEach(function (formId) {
			schedule(formId);
		});
	});
}(jQuery));
