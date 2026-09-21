(function ($) {
	'use strict';

	function showInlineNotice($target, message, type) {
		$target
			.removeClass('is-success is-error')
			.addClass(type === 'success' ? 'is-success' : 'is-error')
			.text(message)
			.attr('hidden', false);
	}

	function resetMetaNotices() {
		$('#socialmcg_error, #socialmcg_success, #socialmcg_integration_result').attr('hidden', true);
		$('#socialmcg_error p, #socialmcg_success p').empty();
		$('#socialmcg_integration_result').empty().removeClass('is-success is-warning');
	}

	function initSettingsPage() {
		$('.socialmcg-color-picker').wpColorPicker();

		var mediaFrame;
		$('#socialmcg_upload_image_button').on('click', function (event) {
			event.preventDefault();

			if (mediaFrame) {
				mediaFrame.open();
				return;
			}

			mediaFrame = wp.media({
				title: socialmcgParams.i18n.selectTemplateImage,
				button: { text: socialmcgParams.i18n.useThisImage },
				multiple: false,
				library: { type: 'image' }
			});

			mediaFrame.on('select', function () {
				var attachment = mediaFrame.state().get('selection').first().toJSON();
				var imageUrl = attachment.url;

				if (attachment.sizes && attachment.sizes.large) {
					imageUrl = attachment.sizes.large.url;
				} else if (attachment.sizes && attachment.sizes.medium) {
					imageUrl = attachment.sizes.medium.url;
				}

				$('#socialmcg_template_image_id').val(attachment.id);
				$('#socialmcg_template_image_preview')
					.removeClass('is-empty')
					.empty()
					.append($('<img>', {
						src: imageUrl,
						alt: socialmcgParams.i18n.selectTemplateImage
					}));
				$('#socialmcg_remove_image_button').removeClass('hidden');
			});

			mediaFrame.open();
		});

		$('#socialmcg_remove_image_button').on('click', function (event) {
			event.preventDefault();
			$('#socialmcg_template_image_id').val('0');
			$('#socialmcg_template_image_preview')
				.addClass('is-empty')
				.empty()
				.append($('<span>').text(socialmcgParams.i18n.noTemplate));
			$(this).addClass('hidden');
		});

		function toggleShadowControls() {
			$('#socialmcg_shadow_controls').toggleClass('is-disabled', !$('#socialmcg_shadow_enabled').is(':checked'));
		}

		function toggleJpegQuality() {
			$('#socialmcg_jpeg_quality_field').toggle($('#socialmcg_output_format').val() === 'jpeg');
		}

		$('#socialmcg_shadow_enabled').on('change', toggleShadowControls);
		$('#socialmcg_output_format').on('change', toggleJpegQuality);
		toggleShadowControls();
		toggleJpegQuality();

		function syncFontResetButtons() {
			$('.socialmcg-reset-font').each(function () {
				var targetId = $(this).data('target');
				var isDefault = $('#' + targetId).val() === socialmcgParams.defaultFontKey;
				$(this).prop('disabled', isDefault);
			});
		}

		$('.socialmcg-font-select').on('change', syncFontResetButtons);
		$('.socialmcg-reset-font').on('click', function (event) {
			event.preventDefault();
			var targetId = $(this).data('target');
			var $select = $('#' + targetId);

			$select.val(socialmcgParams.defaultFontKey).trigger('change').trigger('focus');
			showInlineNotice($('#socialmcg_font_notice'), socialmcgParams.i18n.openSansSelected, 'success');
		});
		syncFontResetButtons();

		$('#socialmcg_upload_font_button').on('click', function (event) {
			event.preventDefault();

			var $button = $(this);
			var $input = $('#socialmcg_font_file');
			var file = $input.get(0).files[0];
			var $notice = $('#socialmcg_font_notice');

			if (!file) {
				showInlineNotice($notice, socialmcgParams.i18n.chooseFont, 'error');
				return;
			}

			var formData = new FormData();
			formData.append('action', 'socialmcg_upload_font');
			formData.append('nonce', socialmcgParams.fontNonce);
			formData.append('font_file', file);

			$button.prop('disabled', true).text(socialmcgParams.i18n.uploadingFont);
			$notice.attr('hidden', true).empty();

			$.ajax({
				url: socialmcgParams.ajaxUrl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				timeout: 30000
			}).done(function (response) {
				if (!response || !response.success) {
					showInlineNotice($notice, response && response.data && response.data.message ? response.data.message : socialmcgParams.i18n.errorOccurred, 'error');
					return;
				}

				var font = response.data.font;
				$('.socialmcg-custom-font-options option[value=""]').remove();
				$('.socialmcg-custom-font-options').append($('<option>', { value: font.key, text: font.label }));

				$('#socialmcg_custom_fonts .socialmcg-empty-fonts').remove();
				var $row = $('<div>', { class: 'socialmcg-font-row', 'data-font-key': font.key });
				$row.append($('<span>').text(font.label));
				$row.append(
					$('<button>', {
						type: 'button',
						class: 'button-link-delete socialmcg-delete-font',
						'data-font-key': font.key,
						text: socialmcgParams.i18n.delete
					})
				);
				$('#socialmcg_custom_fonts').append($row);
				$input.val('');
				showInlineNotice($notice, response.data.message, 'success');
			}).fail(function (xhr, status) {
				var message = status === 'timeout' ? socialmcgParams.i18n.requestTimedOut : socialmcgParams.i18n.errorOccurred;
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					message = xhr.responseJSON.data.message;
				}
				showInlineNotice($notice, message, 'error');
			}).always(function () {
				$button.prop('disabled', false).text(socialmcgParams.i18n.uploadFont);
			});
		});

		$('#socialmcg_custom_fonts').on('click', '.socialmcg-delete-font', function (event) {
			event.preventDefault();

			if (!window.confirm(socialmcgParams.i18n.deleteFontConfirm)) {
				return;
			}

			var $button = $(this);
			var fontKey = $button.data('font-key');
			var $notice = $('#socialmcg_font_notice');

			$button.prop('disabled', true);

			$.post(socialmcgParams.ajaxUrl, {
				action: 'socialmcg_delete_font',
				nonce: socialmcgParams.fontNonce,
				font_key: fontKey
			}).done(function (response) {
				if (!response || !response.success) {
					showInlineNotice($notice, response && response.data && response.data.message ? response.data.message : socialmcgParams.i18n.errorOccurred, 'error');
					$button.prop('disabled', false);
					return;
				}

				$('.socialmcg-font-select option').filter(function () { return $(this).val() === fontKey; }).remove();
				$('.socialmcg-font-select').each(function () {
					if (!$(this).val()) {
						$(this).val(socialmcgParams.defaultFontKey);
					}
				});
				$button.closest('.socialmcg-font-row').remove();

				if (!$('#socialmcg_custom_fonts .socialmcg-font-row').length) {
					$('#socialmcg_custom_fonts').append($('<p>', { class: 'socialmcg-empty-fonts', text: socialmcgParams.i18n.noCustomFonts }));
					$('.socialmcg-custom-font-options').append($('<option>', { value: '', disabled: true, text: socialmcgParams.i18n.noCustomFonts }));
				}

				showInlineNotice($notice, response.data.message, 'success');
			}).fail(function (xhr) {
				var message = socialmcgParams.i18n.errorOccurred;
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					message = xhr.responseJSON.data.message;
				}
				showInlineNotice($notice, message, 'error');
				$button.prop('disabled', false);
			});
		});
	}

	function normalizeStatePath(path) {
		return path.join('').replace(/[^a-z0-9]/gi, '').toLowerCase();
	}

	function getAioseoDesiredValue(path, imageUrl) {
		var normalized = normalizeStatePath(path);

		if (
			/(ogimagetype|facebookimagetype|facebookimagesource|opengraphimagetype|opengraphimagesource)$/.test(normalized)
		) {
			return { matched: true, value: 'custom_image' };
		}

		if (
			/(ogimagecustomurl|ogimageurl|facebookimagecustomurl|facebookimageurl|opengraphimagecustomurl|opengraphimageurl)$/.test(normalized)
		) {
			return { matched: true, value: imageUrl };
		}

		if (/(twitteruseog|twitterusefacebook|twitterusefacebookdata)$/.test(normalized)) {
			return { matched: true, value: false };
		}

		if (/(twitterimagetype|twitterimagesource)$/.test(normalized)) {
			return { matched: true, value: 'custom_image' };
		}

		if (/(twitterimagecustomurl|twitterimageurl)$/.test(normalized)) {
			return { matched: true, value: imageUrl };
		}

		return { matched: false, value: null };
	}

	function countAioseoStateTargets(object, path, seen, depth) {
		if (!object || typeof object !== 'object' || depth > 7 || seen.indexOf(object) !== -1) {
			return 0;
		}

		seen.push(object);
		var count = 0;

		Object.keys(object).forEach(function (key) {
			var nextPath = path.concat([key]);
			if (getAioseoDesiredValue(nextPath, '').matched) {
				count += 1;
				return;
			}

			var value = object[key];
			if (value && typeof value === 'object') {
				count += countAioseoStateTargets(value, nextPath, seen, depth + 1);
			}
		});

		return count;
	}

	function patchAioseoState(object, path, seen, depth, imageUrl) {
		if (!object || typeof object !== 'object' || depth > 7 || seen.indexOf(object) !== -1) {
			return 0;
		}

		seen.push(object);
		var changed = 0;

		Object.keys(object).forEach(function (key) {
			var nextPath = path.concat([key]);
			var desired = getAioseoDesiredValue(nextPath, imageUrl);

			if (desired.matched) {
				try {
					object[key] = desired.value;
					changed += 1;
				} catch (error) {
					// Ignore a readonly property and continue looking for the writable store copy.
				}
				return;
			}

			var value = object[key];
			if (value && typeof value === 'object') {
				changed += patchAioseoState(value, nextPath, seen, depth + 1, imageUrl);
			}
		});

		return changed;
	}

	function getPiniaFromVueApp(app) {
		if (!app) {
			return null;
		}

		if (app.config && app.config.globalProperties && app.config.globalProperties.$pinia) {
			return app.config.globalProperties.$pinia;
		}

		var provides = app._context && app._context.provides ? app._context.provides : null;
		if (!provides) {
			return null;
		}

		var keys = Object.getOwnPropertyNames(provides);
		if (typeof Object.getOwnPropertySymbols === 'function') {
			keys = keys.concat(Object.getOwnPropertySymbols(provides));
		}

		for (var i = 0; i < keys.length; i += 1) {
			var candidate = provides[keys[i]];
			if (candidate && candidate._s && typeof candidate._s.forEach === 'function') {
				return candidate;
			}
		}

		return null;
	}

	function syncAioseoPiniaState(imageUrl) {
		var apps = [];
		var appRoots = document.querySelectorAll('[id*="aioseo"], [class*="aioseo"]');

		Array.prototype.forEach.call(appRoots, function (element) {
			if (element.__vue_app__ && apps.indexOf(element.__vue_app__) === -1) {
				apps.push(element.__vue_app__);
			}
		});

		if (!apps.length) {
			Array.prototype.forEach.call(document.querySelectorAll('body *'), function (element) {
				if (element.__vue_app__ && apps.indexOf(element.__vue_app__) === -1) {
					apps.push(element.__vue_app__);
				}
			});
		}

		var changed = 0;

		apps.forEach(function (app) {
			var pinia = getPiniaFromVueApp(app);
			if (!pinia || !pinia._s || typeof pinia._s.forEach !== 'function') {
				return;
			}

			pinia._s.forEach(function (store) {
				if (!store || !store.$state) {
					return;
				}

				var storeId = String(store.$id || '').toLowerCase();
				var targetCount = countAioseoStateTargets(store.$state, [], [], 0);
				var looksLikePostStore = /post|editor|meta/.test(storeId);

				// Avoid mutating unrelated AIOSEO option stores. A post-editor store normally
				// identifies itself by name; the target-count fallback covers older builds.
				if (!looksLikePostStore && targetCount < 4) {
					return;
				}

				if (typeof store.$patch === 'function') {
					store.$patch(function (state) {
						changed += patchAioseoState(state, [], [], 0, imageUrl);
					});
				} else {
					changed += patchAioseoState(store.$state, [], [], 0, imageUrl);
				}
			});
		});

		return changed;
	}

	function setNativeFieldValue(element, value) {
		var tagName = element.tagName ? element.tagName.toLowerCase() : '';
		var prototype = tagName === 'select' ? window.HTMLSelectElement.prototype : window.HTMLInputElement.prototype;
		if (tagName === 'textarea') {
			prototype = window.HTMLTextAreaElement.prototype;
		}

		var descriptor = Object.getOwnPropertyDescriptor(prototype, 'value');
		if (descriptor && descriptor.set) {
			descriptor.set.call(element, value);
		} else {
			element.value = value;
		}

		element.dispatchEvent(new Event('input', { bubbles: true }));
		element.dispatchEvent(new Event('change', { bubbles: true }));
	}

	function syncAioseoDomFields(imageUrl) {
		var changed = 0;
		var fields = document.querySelectorAll('input, select, textarea');

		Array.prototype.forEach.call(fields, function (field) {
			var descriptor = [
				field.id || '',
				field.name || '',
				field.getAttribute('data-field') || '',
				field.getAttribute('data-key') || '',
				field.getAttribute('aria-label') || ''
			].join(' ').replace(/[^a-z0-9]/gi, '').toLowerCase();

			if (!descriptor || descriptor.indexOf('aioseo') === -1) {
				return;
			}

			if (/(ogimagetype|facebookimagetype|facebookimagesource|twitterimagetype|twitterimagesource)/.test(descriptor)) {
				if (field.tagName && field.tagName.toLowerCase() === 'select') {
					var hasCustomOption = Array.prototype.some.call(field.options || [], function (option) {
						return option.value === 'custom_image';
					});
					if (hasCustomOption) {
						setNativeFieldValue(field, 'custom_image');
						changed += 1;
					}
				}
				return;
			}

			if (/(ogimagecustomurl|facebookimagecustomurl|twitterimagecustomurl)/.test(descriptor)) {
				setNativeFieldValue(field, imageUrl);
				changed += 1;
				return;
			}

			if (/(twitteruseog|twitterusefacebook)/.test(descriptor) && field.type === 'checkbox' && field.checked) {
				field.click();
				changed += 1;
			}
		});

		return changed;
	}

	function syncAioseoEditorUi(imageUrl) {
		if (!imageUrl) {
			return;
		}

		// AIOSEO 5.x uses Vue 3 + Pinia in its post editor. Patch the live post
		// editor store so the currently open Facebook/Twitter fields update now,
		// rather than waiting for a full WordPress page reload. The DOM fallback
		// supports older builds that expose native form fields instead.
		syncAioseoPiniaState(imageUrl);
		syncAioseoDomFields(imageUrl);
	}

	function normalizeEditorTitle(title) {
		if (typeof title === 'string') {
			return title;
		}

		if (title && typeof title.raw === 'string') {
			return title.raw;
		}

		return null;
	}

	function syncCardTitle(postTitle) {
		var normalizedTitle = normalizeEditorTitle(postTitle);
		var $cardTitle = $('#socialmcg_title');

		if (normalizedTitle === null || !$cardTitle.length) {
			return;
		}

		// Preserve the existing behavior: multiline card titles are considered an
		// intentional override and are not replaced by post-title synchronization.
		if ($cardTitle.val().indexOf('\n') === -1) {
			$cardTitle.val(normalizedTitle);
		}
	}

	function initPostTitleSync() {
		var lastStoreTitle = null;

		// WordPress 7.1 always uses an iframe for the block-editor canvas. DOM
		// events from the post title therefore no longer bubble to this admin
		// document. Read the canonical edited title from the core/editor data
		// store instead. wp.data and core/editor have been available since the
		// WordPress 5.x block editor, so this remains compatible with older sites.
		if (window.wp && wp.data && typeof wp.data.subscribe === 'function' && typeof wp.data.select === 'function') {
			var syncFromEditorStore = function () {
				var editorStore;
				var editedTitle;

				try {
					editorStore = wp.data.select('core/editor');
				} catch (error) {
					return;
				}

				if (!editorStore || typeof editorStore.getEditedPostAttribute !== 'function') {
					return;
				}

				editedTitle = normalizeEditorTitle(editorStore.getEditedPostAttribute('title'));
				if (editedTitle === null || editedTitle === lastStoreTitle) {
					return;
				}

				lastStoreTitle = editedTitle;
				syncCardTitle(editedTitle);
			};

			wp.data.subscribe(syncFromEditorStore);
			syncFromEditorStore();
		}

		// Classic Editor and pre-iframe editor fallback. Keeping this listener also
		// supports editor implementations that expose a native post-title field.
		$(document).on('input', '#title, .editor-post-title__input, [name="post_title"]', function () {
			syncCardTitle($(this).val());
		});
	}

	function initPostEditor() {
		var $description = $('#socialmcg_description');
		var $descriptionCount = $('#socialmcg_description_count');

		function updateDescriptionCount() {
			$descriptionCount.text($description.val().length);
		}

		$description.on('input', updateDescriptionCount);
		updateDescriptionCount();
		initPostTitleSync();

		$('#socialmcg_set_aioseo').on('change', function () {
			$.ajax({
				url: socialmcgParams.ajaxUrl,
				type: 'POST',
				data: {
					action: 'socialmcg_save_aioseo_preference',
					nonce: socialmcgParams.aioseoPrefNonce,
					enabled: $(this).is(':checked') ? 1 : 0
				}
			});
		});

		$('#socialmcg_generate_button').on('click', function () {
			var $button = $(this);
			var title = $('#socialmcg_title').val().trim();
			var description = $description.val().trim();
			var setAioseo = $('#socialmcg_set_aioseo').is(':checked') ? 1 : 0;

			resetMetaNotices();

			if (!title) {
				$('#socialmcg_error p').text(socialmcgParams.i18n.titleRequired);
				$('#socialmcg_error').attr('hidden', false);
				return;
			}

			if (!socialmcgParams.postId) {
				$('#socialmcg_error p').text(socialmcgParams.i18n.saveDraftFirst);
				$('#socialmcg_error').attr('hidden', false);
				return;
			}

			$('#socialmcg_loader').attr('hidden', false);
			$button.prop('disabled', true);

			$.ajax({
				url: socialmcgParams.ajaxUrl,
				type: 'POST',
				data: {
					action: 'socialmcg_generate_image',
					nonce: socialmcgParams.generateNonce,
					post_id: socialmcgParams.postId,
					title: title,
					description: description,
					set_aioseo: setAioseo
				},
				timeout: 30000
			}).done(function (response) {
				if (!response || !response.success) {
					$('#socialmcg_error p').text(response && response.data && response.data.message ? response.data.message : socialmcgParams.i18n.errorOccurred);
					$('#socialmcg_error').attr('hidden', false);
					return;
				}

				var data = response.data;
				var $successParagraph = $('#socialmcg_success p').empty();
				$successParagraph.append(document.createTextNode(socialmcgParams.i18n.savedPrefix));

				if (data.attachment_edit_url) {
					$successParagraph.append($('<a>', {
						href: data.attachment_edit_url,
						text: data.filename,
						target: '_blank',
						rel: 'noopener noreferrer'
					}));
				} else {
					$successParagraph.append(document.createTextNode(data.filename));
				}

				$successParagraph.append(document.createTextNode(socialmcgParams.i18n.savedSuffix));
				$('#socialmcg_success').attr('hidden', false);

				if (data.aioseo) {
					$('#socialmcg_integration_result')
						.removeClass('is-success is-warning')
						.addClass(data.aioseo.success ? 'is-success' : 'is-warning')
						.text(data.aioseo.message)
						.attr('hidden', false);

					if (data.aioseo.success) {
						syncAioseoEditorUi(data.image_url);
						window.setTimeout(function () { syncAioseoEditorUi(data.image_url); }, 250);
						window.setTimeout(function () { syncAioseoEditorUi(data.image_url); }, 900);
					}
				}

				var cacheSeparator = data.image_url.indexOf('?') === -1 ? '?' : '&';
				var previewUrl = data.image_url + cacheSeparator + 'socialmcg=' + Date.now();
				var $preview = $('#socialmcg_image_preview').empty();
				$preview.append($('<h4>').text(socialmcgParams.i18n.preview));
				$preview.append($('<img>', {
					src: previewUrl,
					alt: socialmcgParams.i18n.socialCardPreview
				}));
			}).fail(function (xhr, status) {
				var message = status === 'timeout' ? socialmcgParams.i18n.requestTimedOut : socialmcgParams.i18n.errorOccurred;
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					message = xhr.responseJSON.data.message;
				}
				$('#socialmcg_error p').text(message);
				$('#socialmcg_error').attr('hidden', false);
			}).always(function () {
				$('#socialmcg_loader').attr('hidden', true);
				$button.prop('disabled', false);
			});
		});
	}

	$(function () {
		if (socialmcgParams.isSettingsPage) {
			initSettingsPage();
		}

		if (socialmcgParams.isPostEditPage) {
			initPostEditor();
		}
	});
})(jQuery);
