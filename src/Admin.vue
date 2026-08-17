<template>
	<div id="tickets-admin" class="section">
		<h2>{{ t('tickets', 'Tickets') }}</h2>
		<p class="settings-hint">
			{{ t('tickets', 'Choose which existing Nextcloud groups can submit requests and which ones manage them, and customize the list of ticket categories.') }}
		</p>

		<div v-if="loading">
			{{ t('tickets', 'Loading …') }}
		</div>

		<div v-else class="tickets-admin-form">
			<div class="settings-tabs" role="tablist">
				<button
					type="button"
					class="settings-tab"
					:class="{ active: activeTab === 'general' }"
					role="tab"
					:aria-selected="activeTab === 'general'"
					@click="activeTab = 'general'">
					{{ t('tickets', 'General') }}
				</button>
				<button
					type="button"
					class="settings-tab"
					:class="{ active: activeTab === 'categories' }"
					role="tab"
					:aria-selected="activeTab === 'categories'"
					@click="activeTab = 'categories'">
					{{ t('tickets', 'Categories') }}
				</button>
				<button
					type="button"
					class="settings-tab"
					:class="{ active: activeTab === 'attachments' }"
					role="tab"
					:aria-selected="activeTab === 'attachments'"
					@click="activeTab = 'attachments'">
					{{ t('tickets', 'Attachments') }}
				</button>
				<button
					type="button"
					class="settings-tab"
					:class="{ active: activeTab === 'maintenance' }"
					role="tab"
					:aria-selected="activeTab === 'maintenance'"
					@click="activeTab = 'maintenance'">
					{{ t('tickets', 'Maintenance') }}
				</button>
			</div>

			<div v-show="activeTab === 'general'" class="top-row">
				<div class="field">
					<div class="field-label-row">
						<label>{{ t('tickets', 'Board groups (manage requests)') }}</label>
					</div>
					<div class="checkbox-list">
						<label v-for="g in groups" :key="g.id" class="checkbox-option">
							<input v-model="boardGroups" type="checkbox" :value="g.id" @change="scheduleGroupsAutosave">
							{{ g.displayName }}
						</label>
					</div>
				</div>

				<div class="field">
					<div class="field-label-row">
						<label>{{ t('tickets', 'Requester groups (can submit requests)') }}</label>
					</div>
					<div class="checkbox-list">
						<label v-for="g in groups" :key="g.id" class="checkbox-option">
							<input v-model="requesterGroups" type="checkbox" :value="g.id" @change="scheduleGroupsAutosave">
							{{ g.displayName }}
						</label>
					</div>
					<p class="field-hint">
						{{ t('tickets', 'No group selected = all logged-in users.') }}
					</p>
				</div>

				<div class="field">
					<div class="field-label-row">
						<label for="tickets-manager-email">{{ t('tickets', 'Manager mailbox (email notifications)') }}</label>
					</div>
					<input
						id="tickets-manager-email"
						v-model="managerEmail"
						type="email"
						class="manager-email-input"
						placeholder="gestion@example.com"
						@change="scheduleGroupsAutosave">
					<p class="field-hint">
						{{ t('tickets', 'Email address (a group alias or a single person) notified when a ticket is created or taken in charge. Leave empty to send no email to this address.') }}
					</p>
				</div>

				<div class="field">
					<label class="checkbox-option">
						<input v-model="openInNewTab" type="checkbox" @change="scheduleGroupsAutosave">
						{{ t('tickets', 'Open attachment folders in a new tab') }}
					</label>
					<p class="field-hint">
						{{ t('tickets', 'Applies to the "open folder" button in the ticket table. When off, it opens in the current tab instead. PDF previews always open inside the app.') }}
					</p>
				</div>

				<div class="field">
					<div class="field-label-row">
						<label for="tickets-location-label-fr">{{ t('tickets', 'Location field label') }}</label>
					</div>
					<div class="location-label-row location-label-row-header" aria-hidden="true">
						<span class="category-col-label">{{ t('tickets', 'French') }}</span>
						<span class="category-col-label">{{ t('tickets', 'English') }}</span>
					</div>
					<div class="location-label-row">
						<input
							id="tickets-location-label-fr"
							v-model="locationLabelFr"
							type="text"
							class="manager-email-input"
							:placeholder="t('tickets', 'Location (French)')"
							@change="scheduleGroupsAutosave">
						<input
							id="tickets-location-label-en"
							v-model="locationLabelEn"
							type="text"
							class="manager-email-input"
							:placeholder="t('tickets', 'Location (English)')"
							@change="scheduleGroupsAutosave">
					</div>
					<p class="field-hint">
						{{ t('tickets', 'Renames the "Location" field everywhere it appears (request form, ticket table, ticket detail), one label per language like the categories. Leave a language empty to fall back to the other language, then to the default label.') }}
					</p>
				</div>

				<div class="field">
					<label class="checkbox-option">
						<input v-model="dueDateEnabled" type="checkbox" @change="scheduleGroupsAutosave">
						{{ t('tickets', 'Enable the due date field') }}
					</label>
					<p class="field-hint">
						{{ t('tickets', 'When off, the "Due date" field is hidden everywhere (ticket table and ticket detail).') }}
					</p>
				</div>
			</div>

			<div v-show="activeTab === 'attachments'" class="top-row">
				<div class="field">
					<div class="field-label-row">
						<label for="tickets-storage-account">{{ t('tickets', 'Attachment storage account') }}</label>
					</div>
					<input
						id="tickets-storage-account"
						v-model="storageAccountUid"
						type="text"
						class="manager-email-input"
						placeholder="admin"
						@change="scheduleGroupsAutosave">
					<p class="field-hint">
						{{ t('tickets', 'User ID of the Nextcloud account whose Files will store ticket attachments (folder named "Tickets", with one subfolder per ticket number). This account is shared storage, not a personal one — each ticket\'s folder is automatically shared (read-only) with the board group(s) as soon as an attachment is added, so the board can also open it directly in Files. Leave empty to disable attachments.') }}
					</p>
				</div>

				<div class="field">
					<div class="field-label-row">
						<label for="tickets-allowed-extensions">{{ t('tickets', 'Allowed attachment file types') }}</label>
					</div>
					<input
						id="tickets-allowed-extensions"
						v-model="allowedExtensions"
						type="text"
						class="manager-email-input"
						placeholder="jpg, jpeg, png, docx, pdf, txt"
						@change="scheduleGroupsAutosave">
					<p class="field-hint">
						{{ t('tickets', 'Comma-separated list of file extensions (without the dot) accepted as attachments, e.g. "jpg, jpeg, png, docx, pdf, txt". At least one is required.') }}
					</p>
				</div>

				<div class="field">
					<div class="field-label-row">
						<label for="tickets-max-attachment-size">{{ t('tickets', 'Maximum attachment size (MB)') }}</label>
					</div>
					<input
						id="tickets-max-attachment-size"
						v-model.number="maxAttachmentSizeMb"
						type="number"
						min="1"
						step="1"
						class="manager-email-input"
						@change="scheduleGroupsAutosave">
					<p class="field-hint">
						{{ t('tickets', 'Files larger than this are rejected with a clear message instead of a generic upload error.') }}
						<template v-if="serverUploadLimitMb">
							{{ t('tickets', 'Note: this server\'s PHP configuration currently caps uploads at about {limit} MB regardless of this setting.', { limit: serverUploadLimitMb }) }}
						</template>
					</p>
				</div>
			</div>

			<div v-show="activeTab === 'maintenance'" class="top-row">
				<div class="maintenance-section">
					<h3>{{ t('tickets', 'Backup and maintenance') }}</h3>
					<div class="maintenance-row">
						<div>
							<p class="field-hint">
								{{ t('tickets', 'Download every ticket and its comments as an Excel file.') }}
							</p>
							<a class="secondary" :href="url('/api/admin/tickets/export')">
								{{ t('tickets', 'Export tickets (Excel)') }}
							</a>
						</div>
					</div>

					<div class="maintenance-row maintenance-danger">
						<div>
							<p class="field-hint">
								{{ t('tickets', 'Permanently delete all tickets and comments. This cannot be undone — export a backup first.') }}
							</p>
							<div v-if="resetDone" class="reset-done">
									<span class="reset-done-icon" aria-hidden="true">✓</span>
									{{ t('tickets', 'Database has been reset') }}
								</div>
								<button v-else-if="!showResetConfirm" type="button" class="secondary danger" @click="showResetConfirm = true">
								{{ t('tickets', 'Reset database') }}
							</button>
							<div v-else class="reset-confirm">
								<label>
									{{ t('tickets', 'Type RESET to confirm') }}
									<input v-model="resetConfirmText" type="text" placeholder="RESET">
								</label>
								<div class="reset-confirm-actions">
									<button
										type="button"
										class="secondary danger"
										:disabled="resetConfirmText !== 'RESET' || resetting"
										@click="resetDatabase">
										{{ t('tickets', 'Confirm reset') }}
									</button>
									<button type="button" class="secondary" @click="cancelReset">
										{{ t('tickets', 'Cancel') }}
									</button>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<p class="groups-autosave-status">
				<span v-if="groupsSaving" class="autosave-saving">{{ t('tickets', 'Saving…') }}</span>
			</p>

			<div v-show="activeTab === 'categories'">
				<form class="categories-form" @submit.prevent="save">
				<div class="categories-row">
					<div class="field categories-field">
						<label>{{ t('tickets', 'Categories') }}</label>
						<div class="category-list">
							<div class="category-row category-row-header">
								<span class="category-col-label">{{ t('tickets', 'French') }}</span>
								<span class="category-col-label">{{ t('tickets', 'English') }}</span>
								<span class="category-col-spacer" aria-hidden="true" />
							</div>
							<div v-for="(category, index) in categories" :key="category.key" class="category-row">
								<input v-model="category.labelFr" type="text" :placeholder="t('tickets', 'Category name (French)')">
								<input v-model="category.labelEn" type="text" :placeholder="t('tickets', 'Category name (English)')">
								<button
									type="button"
									class="icon-button"
									:disabled="categories.length <= 1"
									:aria-label="t('tickets', 'Remove category')"
									:title="t('tickets', 'Remove category')"
									@click="removeCategory(index)">
									✕
								</button>
							</div>
						</div>
						<button type="button" class="secondary add-category" @click="addCategory">
							{{ t('tickets', 'Add category') }}
						</button>
					</div>

					<div class="category-import-export-section">
						<h3>{{ t('tickets', 'Import / export categories') }}</h3>
						<div class="category-import-export">
							<a class="accent" :href="url('/api/admin/categories/export')">
								{{ t('tickets', 'Export categories') }}
							</a>
							<button type="button" class="accent" @click="$refs.categoriesFile.click()">
								{{ t('tickets', 'Import categories') }}
							</button>
							<input
								ref="categoriesFile"
								type="file"
								accept="application/json"
								class="hidden-file-input"
								@change="importCategories">
						</div>
					</div>
				</div>

				<div class="form-actions">
					<button type="submit" class="primary" :disabled="saving">
						{{ t('tickets', 'Save') }}
					</button>
				</div>
			</form>
			</div>
		</div>
	</div>
</template>

<script>
import { showSuccess, showError } from '@nextcloud/dialogs'
import '@nextcloud/dialogs/styles/toast.scss'

// Compteur local pour donner une :key stable à chaque ligne de catégorie côté Vue,
// y compris les nouvelles catégories pas encore enregistrées (donc sans "value").
let categoryKeySeq = 0

export default {
	name: 'Admin',
	data() {
		return {
			loading: true,
			saving: false,
			// Panneau de réglages actif : 'general' | 'categories' | 'attachments' | 'maintenance'.
			activeTab: 'general',
			groups: [],
			boardGroups: [],
			requesterGroups: [],
			managerEmail: '',
			storageAccountUid: '',
			openInNewTab: true,
			locationLabelFr: '',
			locationLabelEn: '',
			dueDateEnabled: true,
			// Éditée comme une simple liste séparée par des virgules (ex. "jpg, png, pdf"),
			// convertie en tableau juste avant l'envoi au serveur.
			allowedExtensions: '',
			maxAttachmentSizeMb: 20,
			serverUploadLimitMb: null,
			categories: [],
			// Auto-enregistrement des cases à cocher (groupes) : sauvegarde
			// déclenchée au clic, sans passer par le bouton "Save" du formulaire.
			groupsSaving: false,
			groupsAutosaveTimer: null,
			groupsAutosaveRequestId: 0,
			// Dernier état confirmé par le serveur : c'est vers cet état qu'on
			// revient (rollback) si une sauvegarde automatique échoue.
			lastGoodBoardGroups: [],
			lastGoodRequesterGroups: [],
			lastGoodManagerEmail: '',
			lastGoodStorageAccountUid: '',
			lastGoodOpenInNewTab: true,
			lastGoodLocationLabelFr: '',
			lastGoodLocationLabelEn: '',
			lastGoodDueDateEnabled: true,
			lastGoodAllowedExtensions: '',
			lastGoodMaxAttachmentSizeMb: 20,
			// RAZ de la base : confirmation explicite requise (saisie du mot "RESET")
			showResetConfirm: false,
			resetConfirmText: '',
			resetting: false,
			resetDone: false,
		}
	},
	async mounted() {
		const [groupsRes, configRes] = await Promise.all([
			fetch(this.url('/api/admin/groups'), { headers: { requesttoken: OC.requestToken } }),
			fetch(this.url('/api/admin/config'), { headers: { requesttoken: OC.requestToken } }),
		])
		this.groups = await groupsRes.json()
		const config = await configRes.json()
		this.boardGroups = config.boardGroups
		this.requesterGroups = config.requesterGroups
		this.managerEmail = config.managerEmail || ''
		this.storageAccountUid = config.storageAccountUid || ''
		this.openInNewTab = config.openInNewTab !== undefined ? !!config.openInNewTab : true
		this.locationLabelFr = config.locationLabelFr || ''
		this.locationLabelEn = config.locationLabelEn || ''
		this.dueDateEnabled = config.dueDateEnabled !== undefined ? !!config.dueDateEnabled : true
		this.allowedExtensions = (config.allowedExtensions || []).join(', ')
		this.maxAttachmentSizeMb = config.maxAttachmentSizeMb || 20
		this.serverUploadLimitMb = config.serverUploadLimitMb || null
		this.lastGoodBoardGroups = [...config.boardGroups]
		this.lastGoodRequesterGroups = [...config.requesterGroups]
		this.lastGoodManagerEmail = config.managerEmail || ''
		this.lastGoodStorageAccountUid = config.storageAccountUid || ''
		this.lastGoodOpenInNewTab = this.openInNewTab
		this.lastGoodLocationLabelFr = this.locationLabelFr
		this.lastGoodLocationLabelEn = this.locationLabelEn
		this.lastGoodDueDateEnabled = this.dueDateEnabled
		this.lastGoodAllowedExtensions = this.allowedExtensions
		this.lastGoodMaxAttachmentSizeMb = this.maxAttachmentSizeMb
		this.categories = config.categories.map(c => ({
			value: c.value,
			labelFr: c.label_fr || '',
			labelEn: c.label_en || '',
			key: categoryKeySeq++,
		}))
		this.loading = false
	},
	methods: {
		url(path) {
			return OC.generateUrl('/apps/tickets' + path)
		},
		// Convertit la saisie "jpg, .png,  PDF" en tableau propre ["jpg", "png", "pdf"]
		// avant l'envoi au serveur (qui revalide/normalise de toute façon côté PHP).
		parseAllowedExtensions() {
			return this.allowedExtensions
				.split(',')
				.map(ext => ext.trim().replace(/^\./, '').toLowerCase())
				.filter(ext => ext !== '')
		},
		// Toasts en bas de l'écran via @nextcloud/dialogs (le composant officiel
		// utilisé dans toute l'interface Nextcloud) plutôt que des bandeaux
		// custom dans le formulaire.
		notifySuccess(message) {
			showSuccess(message)
		},
		notifyError(message) {
			showError(message)
		},
		// Regroupe les clics rapprochés (cocher plusieurs cases d'affilée) en un
		// seul appel réseau plutôt qu'un par case.
		scheduleGroupsAutosave() {
			clearTimeout(this.groupsAutosaveTimer)
			this.groupsAutosaveTimer = setTimeout(() => this.autosaveGroups(), 400)
		},
		async autosaveGroups() {
			this.groupsSaving = true

			// Identifiant de requête : si une sauvegarde plus récente répond avant
			// celle-ci, on ignore ce résultat pour ne pas écraser un état plus frais.
			const requestId = ++this.groupsAutosaveRequestId

			try {
				const res = await fetch(this.url('/api/admin/config'), {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
					body: JSON.stringify({
						boardGroups: this.boardGroups,
						requesterGroups: this.requesterGroups,
						managerEmail: this.managerEmail,
						storageAccountUid: this.storageAccountUid,
						openInNewTab: this.openInNewTab,
						locationLabelFr: this.locationLabelFr,
						locationLabelEn: this.locationLabelEn,
						dueDateEnabled: this.dueDateEnabled,
						allowedExtensions: this.parseAllowedExtensions(),
						maxAttachmentSizeMb: this.maxAttachmentSizeMb,
						categories: this.categories
							.map(({ value, labelFr, labelEn }) => ({
								value,
								label_fr: (labelFr || '').trim(),
								label_en: (labelEn || '').trim(),
							}))
							.filter(c => c.label_fr !== '' || c.label_en !== ''),
					}),
				})
				if (requestId !== this.groupsAutosaveRequestId) {
					return
				}
				if (!res.ok) {
					const body = await res.json().catch(() => ({}))
					throw new Error(body.message || 'Error')
				}
				const saved = await res.json()
				this.boardGroups = saved.boardGroups
				this.requesterGroups = saved.requesterGroups
				this.managerEmail = saved.managerEmail || ''
				this.storageAccountUid = saved.storageAccountUid || ''
				this.openInNewTab = saved.openInNewTab !== undefined ? !!saved.openInNewTab : true
				this.locationLabelFr = saved.locationLabelFr || ''
				this.locationLabelEn = saved.locationLabelEn || ''
				this.dueDateEnabled = saved.dueDateEnabled !== undefined ? !!saved.dueDateEnabled : true
				this.allowedExtensions = (saved.allowedExtensions || []).join(', ')
				this.maxAttachmentSizeMb = saved.maxAttachmentSizeMb || 20
				this.lastGoodBoardGroups = [...saved.boardGroups]
				this.lastGoodRequesterGroups = [...saved.requesterGroups]
				this.lastGoodManagerEmail = saved.managerEmail || ''
				this.lastGoodStorageAccountUid = saved.storageAccountUid || ''
				this.lastGoodOpenInNewTab = this.openInNewTab
				this.lastGoodLocationLabelFr = this.locationLabelFr
				this.lastGoodLocationLabelEn = this.locationLabelEn
				this.lastGoodDueDateEnabled = this.dueDateEnabled
				this.lastGoodAllowedExtensions = this.allowedExtensions
				this.lastGoodMaxAttachmentSizeMb = this.maxAttachmentSizeMb
				if (saved.attachmentMigrationWarning) {
					this.notifyError(this.t('tickets', 'Attachments could not be fully migrated to the new storage account. Please check the server logs and move them manually if needed.'))
				} else {
					this.notifySuccess(this.t('tickets', 'Settings saved'))
				}
			} catch (e) {
				if (requestId !== this.groupsAutosaveRequestId) {
					return
				}
				// Rollback : on revient au dernier état confirmé par le serveur
				// plutôt qu'à celui d'avant ce seul essai, au cas où plusieurs
				// modifications se seraient enchaînées avant l'échec.
				this.boardGroups = [...this.lastGoodBoardGroups]
				this.requesterGroups = [...this.lastGoodRequesterGroups]
				this.managerEmail = this.lastGoodManagerEmail
				this.storageAccountUid = this.lastGoodStorageAccountUid
				this.openInNewTab = this.lastGoodOpenInNewTab
				this.locationLabelFr = this.lastGoodLocationLabelFr
				this.locationLabelEn = this.lastGoodLocationLabelEn
				this.dueDateEnabled = this.lastGoodDueDateEnabled
				this.allowedExtensions = this.lastGoodAllowedExtensions
				this.maxAttachmentSizeMb = this.lastGoodMaxAttachmentSizeMb
				this.notifyError(this.t('tickets', 'Could not save settings'))
			} finally {
				if (requestId === this.groupsAutosaveRequestId) {
					this.groupsSaving = false
				}
			}
		},
		addCategory() {
			this.categories.push({ value: '', labelFr: '', labelEn: '', key: categoryKeySeq++ })
		},
		removeCategory(index) {
			if (this.categories.length <= 1) {
				return
			}
			this.categories.splice(index, 1)
		},
		async importCategories(event) {
			const file = event.target.files && event.target.files[0]
			event.target.value = ''
			if (!file) {
				return
			}

			const formData = new FormData()
			formData.append('file', file)

			try {
				const res = await fetch(this.url('/api/admin/categories/import'), {
					method: 'POST',
					headers: { requesttoken: OC.requestToken },
					body: formData,
				})
				if (!res.ok) {
					const body = await res.json().catch(() => ({}))
					throw new Error(body.message || 'Error')
				}
				const saved = await res.json()
				this.categories = saved.categories.map(c => ({
					value: c.value,
					labelFr: c.label_fr || '',
					labelEn: c.label_en || '',
					key: categoryKeySeq++,
				}))
				this.notifySuccess(this.t('tickets', 'Categories imported'))
			} catch (e) {
				this.notifyError(this.t('tickets', 'Could not import categories'))
			}
		},
		cancelReset() {
			this.showResetConfirm = false
			this.resetConfirmText = ''
		},
		async resetDatabase() {
			if (this.resetConfirmText !== 'RESET') {
				return
			}
			this.resetting = true
			try {
				const res = await fetch(this.url('/api/admin/reset'), {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
					body: JSON.stringify({ confirm: this.resetConfirmText }),
				})
				if (!res.ok) {
					const body = await res.json().catch(() => ({}))
					throw new Error(body.message || 'Error')
				}
				this.notifySuccess(this.t('tickets', 'Database has been reset'))
				this.showResetConfirm = false
				this.resetConfirmText = ''
				this.resetDone = true
			} catch (e) {
				this.notifyError(this.t('tickets', 'Could not reset the database'))
			} finally {
				this.resetting = false
			}
		},
		async save() {
			this.saving = true
			try {
				const res = await fetch(this.url('/api/admin/config'), {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
					body: JSON.stringify({
						boardGroups: this.boardGroups,
						requesterGroups: this.requesterGroups,
						managerEmail: this.managerEmail,
						storageAccountUid: this.storageAccountUid,
						openInNewTab: this.openInNewTab,
						locationLabelFr: this.locationLabelFr,
						locationLabelEn: this.locationLabelEn,
						dueDateEnabled: this.dueDateEnabled,
						allowedExtensions: this.parseAllowedExtensions(),
						maxAttachmentSizeMb: this.maxAttachmentSizeMb,
						categories: this.categories
							.map(({ value, labelFr, labelEn }) => ({
								value,
								label_fr: (labelFr || '').trim(),
								label_en: (labelEn || '').trim(),
							}))
							.filter(c => c.label_fr !== '' || c.label_en !== ''),
					}),
				})
				if (!res.ok) {
					const body = await res.json().catch(() => ({}))
					throw new Error(body.message || 'Error')
				}
				const saved = await res.json()
				this.boardGroups = saved.boardGroups
				this.requesterGroups = saved.requesterGroups
				this.managerEmail = saved.managerEmail || ''
				this.lastGoodManagerEmail = saved.managerEmail || ''
				this.storageAccountUid = saved.storageAccountUid || ''
				this.lastGoodStorageAccountUid = saved.storageAccountUid || ''
				this.openInNewTab = saved.openInNewTab !== undefined ? !!saved.openInNewTab : true
				this.lastGoodOpenInNewTab = this.openInNewTab
				this.locationLabelFr = saved.locationLabelFr || ''
				this.locationLabelEn = saved.locationLabelEn || ''
				this.lastGoodLocationLabelFr = this.locationLabelFr
				this.lastGoodLocationLabelEn = this.locationLabelEn
				this.dueDateEnabled = saved.dueDateEnabled !== undefined ? !!saved.dueDateEnabled : true
				this.lastGoodDueDateEnabled = this.dueDateEnabled
				this.allowedExtensions = (saved.allowedExtensions || []).join(', ')
				this.lastGoodAllowedExtensions = this.allowedExtensions
				this.maxAttachmentSizeMb = saved.maxAttachmentSizeMb || 20
				this.lastGoodMaxAttachmentSizeMb = this.maxAttachmentSizeMb
				this.categories = saved.categories.map(c => ({
					value: c.value,
					labelFr: c.label_fr || '',
					labelEn: c.label_en || '',
					key: categoryKeySeq++,
				}))
				if (saved.attachmentMigrationWarning) {
					this.notifyError(this.t('tickets', 'Attachments could not be fully migrated to the new storage account. Please check the server logs and move them manually if needed.'))
				} else {
					this.notifySuccess(this.t('tickets', 'Settings saved'))
				}
			} catch (e) {
				this.notifyError(this.t('tickets', 'Could not save settings'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.tickets-admin-form {
	display: flex;
	flex-direction: column;
	gap: 20px;
	margin-top: 12px;
	max-width: 100%;
}
.settings-tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-bottom: 20px;
	border-bottom: 1px solid var(--color-border, #ccc);
}
.settings-tab {
	padding: 10px 16px;
	border: none;
	border-bottom: 2px solid transparent;
	background: none;
	color: var(--color-text-maxcontrast, #767676);
	font-size: 1em;
	font-weight: 600;
	cursor: pointer;
	/* Chevauche légèrement la bordure du conteneur pour que la bordure active
	   du bouton la remplace visuellement plutôt que de s'empiler dessus. */
	margin-bottom: -1px;
}
.settings-tab:hover {
	color: var(--color-main-text);
}
.settings-tab.active {
	color: var(--color-main-text);
	border-bottom-color: var(--color-primary-element, #0082c9);
}
.top-row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	gap: 24px;
}
.top-row > .field {
	flex: 1 1 260px;
	min-width: 0;
}
.top-row .maintenance-section {
	flex: 1 1 260px;
	min-width: 0;
}
.categories-form {
	max-width: 900px;
}
.categories-row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	gap: 32px;
}
.categories-field {
	flex: 1 1 420px;
	min-width: 0;
}
.category-import-export-section {
	flex: 0 0 220px;
	padding-left: 24px;
	border-left: 1px solid var(--color-border, #eee);
	display: flex;
	flex-direction: column;
	gap: 10px;
}
.category-import-export-section h3 {
	margin: 0;
	font-size: 1em;
}
.field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}
.field > label {
	font-weight: 600;
}
.field-hint {
	margin: 0;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #767676);
}
.manager-email-input {
	width: 100%;
	max-width: 360px;
}
.location-label-row {
	display: flex;
	gap: 8px;
	max-width: 360px;
}
.location-label-row .manager-email-input {
	max-width: none;
}
.location-label-row-header {
	margin-bottom: -2px;
}
/* Les deux libellés n'ont pas la même longueur ("Board groups (manage
   requests)" vs "Requester groups (can submit requests)") : sur les largeurs
   intermédiaires l'un des deux passe sur 2 lignes et décale sa liste de
   cases à cocher par rapport à l'autre. On réserve la hauteur de 2 lignes
   aux deux labels pour que les listes démarrent toujours au même niveau. */
.field-label-row {
	display: flex;
	align-items: flex-end;
	min-height: 2.6em;
}
.groups-autosave-status {
	margin: -8px 0 0;
	min-height: 1.2em;
	font-size: 0.85em;
}
.autosave-saving {
	color: var(--color-text-maxcontrast, #767676);
}
.checkbox-list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 8px 10px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	max-height: 200px;
	overflow-y: auto;
}
.checkbox-option {
	display: flex;
	align-items: center;
	gap: 8px;
	font-weight: normal;
}
.category-list {
	display: flex;
	flex-direction: column;
	gap: 6px;
}
.category-row {
	display: flex;
	align-items: center;
	gap: 8px;
}
.category-row-header {
	padding: 0 0 0 0;
}
.category-col-label {
	flex: 1;
	font-size: 0.85em;
	font-weight: 600;
	color: var(--color-text-maxcontrast, #767676);
}
.category-col-spacer {
	width: 34px;
	flex: 0 0 auto;
}
.category-row input[type="text"] {
	flex: 1;
	min-width: 0;
	padding: 8px 10px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}
.icon-button {
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	padding: 6px 10px;
}
.icon-button:disabled {
	opacity: 0.4;
	cursor: not-allowed;
}
.add-category {
	align-self: flex-start;
	padding: 6px 14px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}
.form-actions {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 4px;
}
.form-actions button.primary {
	padding: 8px 20px;
	border: none;
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	font-weight: 600;
	cursor: pointer;
}
.category-import-export {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 10px;
}
.hidden-file-input {
	display: none;
}
button.secondary,
a.secondary {
	display: inline-block;
	padding: 6px 14px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	text-decoration: none;
}
button.accent,
a.accent {
	display: inline-block;
	width: 100%;
	box-sizing: border-box;
	text-align: center;
	padding: 6px 14px;
	border: 1px solid var(--color-primary-element, #0082c9);
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	font-weight: 600;
	cursor: pointer;
	text-decoration: none;
}
button.accent:hover,
a.accent:hover {
	background-color: var(--color-primary-element-hover, #005c99);
	border-color: var(--color-primary-element-hover, #005c99);
}
.maintenance-section {
	margin-top: 0;
	max-width: 500px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}
.maintenance-section h3 {
	margin: 0;
}
.maintenance-row {
	display: flex;
	flex-direction: column;
	gap: 6px;
}
.maintenance-row > div {
	display: flex;
	flex-direction: column;
	gap: 6px;
	align-items: flex-start;
}
.maintenance-danger {
	padding: 12px;
	border: 1px solid #d3312a;
	border-radius: var(--border-radius, 4px);
}
button.danger {
	background-color: #d3312a !important;
	border-color: #d3312a !important;
	color: #fff !important;
	font-weight: 600;
}
button.danger:hover {
	background-color: #a5231d !important;
	border-color: #a5231d !important;
}
button.danger:disabled {
	background-color: #e59c98 !important;
	border-color: #e59c98 !important;
	color: #fff !important;
	opacity: 1;
}
.reset-done {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 6px 14px;
	border: 1px solid var(--color-success, #2b8a3e);
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-success, #2b8a3e);
	color: #fff;
	font-weight: 600;
}
.reset-done-icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 18px;
	height: 18px;
	border-radius: 50%;
	background-color: #fff;
	color: var(--color-success, #2b8a3e);
	font-size: 0.8em;
	line-height: 1;
	flex: 0 0 auto;
}
.reset-confirm {
	display: flex;
	flex-direction: column;
	gap: 10px;
}
.reset-confirm label {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 0.9em;
}
.reset-confirm input[type="text"] {
	padding: 8px 10px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	max-width: 200px;
}
.reset-confirm-actions {
	display: flex;
	gap: 10px;
}
@media (max-width: 760px) {
	.categories-row {
		flex-direction: column;
	}
	.category-import-export-section {
		padding-left: 0;
		padding-top: 20px;
		border-left: none;
		border-top: 1px solid var(--color-border, #eee);
	}
	.settings-tabs {
		overflow-x: auto;
	}
}
</style>
