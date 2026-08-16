<template>
	<div class="tickets-app">
		<p v-if="!canRequest" class="access-denied">
			{{ t('tickets', 'You do not have access to this application.') }}
		</p>

		<template v-else>

		<div v-if="showNewTicketModal" class="modal-overlay" @click.self="showNewTicketModal = false">
			<div class="modal modal-detail" role="dialog" aria-modal="true">
				<div class="modal-header">
					<h2>{{ t('tickets', 'New ticket') }}</h2>
					<button type="button" class="modal-close" :aria-label="t('tickets', 'Close')" @click="showNewTicketModal = false">
						×
					</button>
				</div>
				<form class="new-ticket-form" @submit.prevent="createTicket()">
					<div class="modal-columns">
						<div class="modal-col modal-col-left">
							<div class="field">
								<label for="ticket-title">{{ t('tickets', 'Title') }}</label>
								<input id="ticket-title" v-model="form.title" type="text" required @keydown.enter.prevent>
							</div>
							<div class="field">
								<label for="ticket-description">{{ t('tickets', 'Description') }}</label>
								<textarea id="ticket-description" v-model="form.description" rows="4" />
							</div>
							<div class="field-row">
								<div class="field field-inline">
									<label for="ticket-requester-name">{{ t('tickets', 'Name') }}</label>
									<input id="ticket-requester-name" v-model="form.requesterName" type="text" @keydown.enter.prevent>
								</div>
								<div class="field field-inline">
									<label for="ticket-requester-location">{{ locationFieldLabel() }}</label>
									<input id="ticket-requester-location" v-model="form.requesterLocation" type="text" @keydown.enter.prevent>
								</div>
							</div>
							<div class="field-row">
								<div class="field field-inline">
									<label for="ticket-category">{{ t('tickets', 'Category') }}</label>
									<select id="ticket-category" v-model="form.category">
										<option v-for="c in categories" :key="c.value" :value="c.value">{{ categoryLabel(c.value) }}</option>
									</select>
								</div>
								<div class="field field-inline">
									<label for="ticket-priority">{{ t('tickets', 'Priority') }}</label>
									<select id="ticket-priority" v-model="form.priority" class="priority-select" :class="'priority-' + form.priority">
										<option v-for="p in priorities" :key="p" :value="p">{{ priorityIcon(p) }} {{ priorityLabel(p) }}</option>
									</select>
								</div>
							</div>
						</div>
						<div class="modal-col modal-col-right">
							<div
								class="field dropzone"
								:class="{ dragging: newTicketDragging }"
								@dragover.prevent="newTicketDragging = true"
								@dragleave.prevent="newTicketDragging = false"
								@drop.prevent="onNewTicketFilesDrop">
								<label for="ticket-attachments">{{ t('tickets', 'Attachments') }}</label>
								<input id="ticket-attachments" type="file" multiple :accept="allowedAttachmentAccept" @change="onNewTicketFilesChange">
								<p class="field-hint">
									{{ allowedAttachmentsHint }}
								</p>
								<p class="field-hint dropzone-hint">
									{{ t('tickets', 'Or drag and drop files here') }}
								</p>
								<ul v-if="pendingFiles.length" class="attachments-list pending-files-list">
									<li v-for="(f, index) in pendingFiles" :key="index">
										<a :href="pendingFileUrl(f)" :download="f.name">{{ f.name }}</a>
										<span class="attachment-meta">{{ formatFileSize(f.size) }}</span>
										<a
											class="icon-button icon-button-group-start"
											:href="pendingFileUrl(f)"
											:download="f.name"
											:aria-label="t('tickets', 'Download attachment')"
											:title="t('tickets', 'Download attachment')">
											⭳
										</a>
										<button
											type="button"
											class="icon-button"
											:aria-label="t('tickets', 'Remove file')"
											:title="t('tickets', 'Remove file')"
											@click="removePendingFile(index)">
											✕
										</button>
									</li>
								</ul>
							</div>
						</div>
					</div>
					<div class="form-actions">
						<button type="submit" class="primary" :disabled="submitting">
							{{ t('tickets', 'Submit') }}
						</button>
						<span v-if="submitting" class="upload-status">
							<span class="spinner" aria-hidden="true"></span>
							{{ attachmentUploading ? t('tickets', 'Uploading attachments…') : t('tickets', 'Sending…') }}
						</span>
					</div>
				</form>
			</div>
		</div>

		<section class="ticket-list">
			<div class="ticket-list-header">
				<h2>{{ isBoardMember ? t('tickets', 'All tickets') : t('tickets', 'My tickets') }}</h2>
				<button type="button" class="primary" @click="showNewTicketModal = true">
					+ {{ t('tickets', 'New ticket') }}
				</button>
			</div>
			<div v-if="isBoardMember" class="ticket-list-actions">
				<a class="export-link" :href="exportUrl()">
					⭳ {{ t('tickets', 'Export current view') }}
				</a>
			</div>
			<div class="status-counts" role="group" :aria-label="t('tickets', 'Filter by status')">
				<button
					type="button"
					class="status-count"
					:class="{ active: filters.status === '' }"
					:aria-pressed="filters.status === ''"
					@click="selectStatusFilter('')">
					{{ t('tickets', 'All') }} <span class="status-count-badge">{{ statusCounts.all || 0 }}</span>
				</button>
				<button
					v-for="s in statuses"
					:key="s"
					type="button"
					class="status-count"
					:class="{ active: filters.status === s }"
					:aria-pressed="filters.status === s"
					@click="selectStatusFilter(s)">
					{{ statusLabel(s) }} <span class="status-count-badge">{{ statusCounts[s] || 0 }}</span>
				</button>
			</div>
			<div class="ticket-filters">
				<div class="filter-field">
					<label for="filter-priority">{{ t('tickets', 'Priority') }}</label>
					<select id="filter-priority" v-model="filters.priority" @change="applyFilters">
						<option value="">{{ t('tickets', 'All') }}</option>
						<option v-for="p in priorities" :key="p" :value="p">{{ priorityIcon(p) }} {{ priorityLabel(p) }}</option>
					</select>
				</div>
				<div class="filter-field">
					<label for="filter-category">{{ t('tickets', 'Category') }}</label>
					<select id="filter-category" v-model="filters.category" @change="applyFilters">
						<option value="">{{ t('tickets', 'All') }}</option>
						<option v-for="c in categories" :key="c.value" :value="c.value">{{ categoryLabel(c.value) }}</option>
					</select>
				</div>
				<div v-if="isBoardMember" class="filter-field">
					<label for="filter-assigned">{{ t('tickets', 'Assigned to') }}</label>
					<select id="filter-assigned" v-model="filters.assignedUid" @change="applyFilters">
						<option value="">{{ t('tickets', 'All') }}</option>
						<option value="_me">{{ t('tickets', 'Me') }}</option>
						<option value="_unassigned">{{ t('tickets', 'Unassigned') }}</option>
						<option v-for="m in boardMembers" :key="m.uid" :value="m.uid">{{ m.displayName }}</option>
					</select>
				</div>
				<div class="filter-field filter-field-search">
					<label for="filter-search">{{ t('tickets', 'Search') }}</label>
					<input
						id="filter-search"
						v-model="filters.search"
						type="search"
						:placeholder="t('tickets', 'Search title, description, requester…')"
						@input="onSearchInput">
				</div>
				<button v-if="hasActiveFilters()" type="button" class="filter-reset" @click="resetFilters">
					{{ t('tickets', 'Reset filters') }}
				</button>
			</div>
			<table>
				<thead>
					<tr>
						<th class="sortable" @click="toggleSort('id')">
							{{ t('tickets', 'Ticket #') }}<span class="sort-arrow" :class="{ 'sort-arrow-active': sortIndicatorActive('id') }">{{ sortIndicator('id') }}</span>
						</th>
						<th class="sortable" @click="toggleSort('title')">
							{{ t('tickets', 'Title') }}<span class="sort-arrow" :class="{ 'sort-arrow-active': sortIndicatorActive('title') }">{{ sortIndicator('title') }}</span>
						</th>
						<th v-if="isBoardMember" class="sortable" @click="toggleSort('requester_name')">
							{{ t('tickets', 'Requester') }}<span class="sort-arrow" :class="{ 'sort-arrow-active': sortIndicatorActive('requester_name') }">{{ sortIndicator('requester_name') }}</span>
						</th>
						<th v-if="isBoardMember" class="sortable" @click="toggleSort('requester_location')">
							{{ locationFieldLabel() }}<span class="sort-arrow" :class="{ 'sort-arrow-active': sortIndicatorActive('requester_location') }">{{ sortIndicator('requester_location') }}</span>
						</th>
						<th class="sortable" @click="toggleSort('assigned_uid')">
							{{ t('tickets', 'Assigned to') }}<span class="sort-arrow" :class="{ 'sort-arrow-active': sortIndicatorActive('assigned_uid') }">{{ sortIndicator('assigned_uid') }}</span>
						</th>
						<th class="sortable" @click="toggleSort('category')">
							{{ t('tickets', 'Category') }}<span class="sort-arrow" :class="{ 'sort-arrow-active': sortIndicatorActive('category') }">{{ sortIndicator('category') }}</span>
						</th>
						<th class="sortable" @click="toggleSort('status')">
							{{ t('tickets', 'Status') }}<span class="sort-arrow" :class="{ 'sort-arrow-active': sortIndicatorActive('status') }">{{ sortIndicator('status') }}</span>
						</th>
						<th class="sortable" @click="toggleSort('priority')">
							{{ t('tickets', 'Priority') }}<span class="sort-arrow" :class="{ 'sort-arrow-active': sortIndicatorActive('priority') }">{{ sortIndicator('priority') }}</span>
						</th>
						<th v-if="dueDateEnabled" class="sortable" @click="toggleSort('due_at')">
							{{ t('tickets', 'Due date') }}<span class="sort-arrow" :class="{ 'sort-arrow-active': sortIndicatorActive('due_at') }">{{ sortIndicator('due_at') }}</span>
						</th>
						<th v-if="isBoardMember" class="col-attachments">
							{{ t('tickets', 'Attachments') }}
						</th>
						<th class="sortable" @click="toggleSort('created_at')">
							{{ t('tickets', 'Date') }}<span class="sort-arrow" :class="{ 'sort-arrow-active': sortIndicatorActive('created_at') }">{{ sortIndicator('created_at') }}</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="ticket in tickets" :key="ticket.id" :class="{ 'ticket-unread': ticket.hasUnread }" @click="openTicket(ticket.id)">
						<td class="ticket-number" :data-label="t('tickets', 'Ticket #')">
							<span v-if="ticket.hasUnread" class="unread-badge" :title="t('tickets', 'Unread activity')">{{ unreadBadgeLabel(ticket) }}</span>
							{{ ticket.ticketNumber }}
						</td>
						<td :data-label="t('tickets', 'Title')">{{ ticket.title }}</td>
						<td v-if="isBoardMember" :data-label="t('tickets', 'Requester')">{{ ticket.requesterName || '—' }}</td>
						<td v-if="isBoardMember" :data-label="locationFieldLabel()">{{ ticket.requesterLocation || '—' }}</td>
						<td :data-label="t('tickets', 'Assigned to')">{{ ticket.assignedDisplayName || '—' }}</td>
						<td :data-label="t('tickets', 'Category')">{{ categoryLabel(ticket.category) }}</td>
						<td :data-label="t('tickets', 'Status')">
							<span class="status-badge" :class="'status-' + (ticket.status || 'new')">{{ statusLabel(ticket.status || 'new') }}</span>
						</td>
						<td :data-label="t('tickets', 'Priority')">
							<span class="priority-badge" :class="'priority-' + (ticket.priority || 'normal')">
								<span class="priority-icon" aria-hidden="true">{{ priorityIcon(ticket.priority || 'normal') }}</span>
								{{ priorityLabel(ticket.priority || 'normal') }}
							</span>
						</td>
						<td v-if="dueDateEnabled" :data-label="t('tickets', 'Due date')">
							<span v-if="ticket.dueAt" :class="{ 'due-overdue': isOverdue(ticket), 'due-soon': isDueSoon(ticket) }">
								{{ formatDate(ticket.dueAt) }}
							</span>
							<span v-else>—</span>
						</td>
						<td v-if="isBoardMember" class="col-attachments" :data-label="t('tickets', 'Attachments')">
							<button
								v-if="ticket.attachmentCount"
								type="button"
								class="attachment-link"
								:aria-label="t('tickets', '{count} attachment(s), open folder', { count: ticket.attachmentCount })"
								:title="t('tickets', '{count} attachment(s), open folder', { count: ticket.attachmentCount })"
								@click.stop="openTicketAttachments(ticket.id)">
								<span class="attachment-icon" aria-hidden="true">📁</span>{{ ticket.attachmentCount }}
							</button>
							<span v-else class="attachment-none">—</span>
						</td>
						<td :data-label="t('tickets', 'Date')">{{ formatDateTime(ticket.createdAt) }}</td>
					</tr>
				</tbody>
			</table>
			<div v-if="totalPages() > 1" class="pagination">
				<button type="button" :disabled="currentPage === 1" @click="goToPage(currentPage - 1)">
					{{ t('tickets', 'Previous') }}
				</button>
				<span class="pagination-status">{{ t('tickets', 'Page {current} of {total}', { current: currentPage, total: totalPages() }) }}</span>
				<button type="button" :disabled="currentPage === totalPages()" @click="goToPage(currentPage + 1)">
					{{ t('tickets', 'Next') }}
				</button>
			</div>
		</section>

		<div v-if="selected" class="modal-overlay" @click.self="selected = null">
			<div class="modal modal-detail" role="dialog" aria-modal="true">
				<div class="modal-header">
					<h2>{{ selected.title }}</h2>
					<div class="modal-header-actions">
						<span v-if="selected.assignedDisplayName" class="modal-assigned">
							{{ t('tickets', 'Assigned to') }}: {{ selected.assignedDisplayName }}
						</span>
						<button
							v-if="isBoardMember"
							type="button"
							class="icon-button modal-delete"
							:aria-label="t('tickets', 'Delete ticket')"
							:title="t('tickets', 'Delete ticket')"
							@click="deleteTicket">
							🗑
						</button>
						<button type="button" class="modal-close" :aria-label="t('tickets', 'Close')" @click="selected = null">
							×
						</button>
					</div>
				</div>
				<p class="ticket-detail-meta">{{ selected.ticketNumber }} — {{ formatDateTime(selected.createdAt) }}</p>
				<div class="modal-columns">
					<div class="modal-col modal-col-left">
						<div class="ticket-detail-fields">
							<div class="field field-inline">
								<label for="detail-requester-name">{{ t('tickets', 'Name') }}</label>
								<input
									v-if="isBoardMember"
									id="detail-requester-name"
									v-model="selected.requesterName"
									type="text"
									@blur="saveRequesterInfo">
								<span v-else>{{ selected.requesterName || '—' }}</span>
							</div>
							<div class="field field-inline">
								<label for="detail-requester-location">{{ locationFieldLabel() }}</label>
								<input
									v-if="isBoardMember"
									id="detail-requester-location"
									v-model="selected.requesterLocation"
									type="text"
									@blur="saveRequesterInfo">
								<span v-else>{{ selected.requesterLocation || '—' }}</span>
							</div>
							<div class="field field-inline">
								<label for="detail-status">{{ t('tickets', 'Status') }}</label>
								<select v-if="isBoardMember" id="detail-status" :value="selected.status" @change="onStatusSelectChange($event.target.value)">
									<option v-for="s in statuses" :key="s" :value="s">{{ statusLabel(s) }}</option>
								</select>
								<span v-else>{{ statusLabel(selected.status) }}</span>
							</div>
							<div class="field field-inline">
								<label>{{ t('tickets', 'Priority') }}</label>
								<select v-if="isBoardMember" v-model="selected.priority" class="priority-select" :class="'priority-' + selected.priority">
									<option v-for="p in priorities" :key="p" :value="p">{{ priorityIcon(p) }} {{ priorityLabel(p) }}</option>
								</select>
								<span v-else class="priority-badge" :class="'priority-' + selected.priority">
									<span class="priority-icon" aria-hidden="true">{{ priorityIcon(selected.priority) }}</span>
									{{ priorityLabel(selected.priority) }}
								</span>
							</div>
							<div v-if="isBoardMember" class="field field-inline">
								<label for="ticket-assignee">{{ t('tickets', 'Assigned to') }}</label>
								<select id="ticket-assignee" :value="selected.assignedUid || ''" :disabled="reassigning" @change="changeAssignee($event.target.value)">
									<option value="">{{ t('tickets', 'Unassigned') }}</option>
									<option v-for="m in boardMembers" :key="m.uid" :value="m.uid">{{ m.displayName }}</option>
								</select>
							</div>
							<div v-if="dueDateEnabled && isBoardMember" class="field field-inline">
								<label for="detail-due-at">{{ t('tickets', 'Due date') }}</label>
								<input
									id="detail-due-at"
									type="date"
									:value="toDateInputValue(selected.dueAt)"
									:class="{ 'due-overdue': isOverdue(selected), 'due-soon': isDueSoon(selected) }"
									@change="saveDueDate($event.target.value)">
							</div>
							<div v-else-if="dueDateEnabled && selected.dueAt" class="field field-inline">
								<label>{{ t('tickets', 'Due date') }}</label>
								<span :class="{ 'due-overdue': isOverdue(selected), 'due-soon': isDueSoon(selected) }">
									{{ formatDate(selected.dueAt) }}
								</span>
							</div>
						</div>
						<p class="ticket-detail-description">{{ selected.description }}</p>
						<ul class="comments">
							<li v-for="entry in timelineEntries()" :key="entry.id">
								<span v-if="entry.kind === 'comment'" class="comment-message">
									<strong>{{ entry.data.authorUid }}</strong> — <span class="comment-message-text">{{ entry.data.message }}</span>
								</span>
								<span v-else class="activity-entry">{{ activityLabel(entry.data) }}</span>
								<span class="activity-time">{{ formatDateTime(entry.createdAt) }}</span>
							</li>
						</ul>
						<form v-if="canComment()" class="comment-form" @submit.prevent="submitComment">
							<textarea v-model="commentText" rows="2" :placeholder="t('tickets', 'Add a comment')" />
							<button type="submit" class="primary" :disabled="submitting">
								{{ t('tickets', 'Submit') }}
							</button>
						</form>
						<p v-else class="comments-locked">
							{{ t('tickets', 'This ticket is resolved, comments are locked.') }}
						</p>
					</div>
					<div class="modal-col modal-col-right">
						<div class="attachments-section">
							<h3 class="attachments-section-header">
								{{ t('tickets', 'Attachments') }}
								<button
									v-if="isBoardMember && selected.attachments && selected.attachments.length"
									type="button"
									class="attachment-link"
									:aria-label="t('tickets', '{count} attachment(s), open folder', { count: selected.attachments.length })"
									:title="t('tickets', '{count} attachment(s), open folder', { count: selected.attachments.length })"
									@click="openTicketAttachments(selected.id)">
									<span class="attachment-icon" aria-hidden="true">📁</span>{{ selected.attachments.length }}
								</button>
							</h3>
							<ul v-if="selected.attachments && selected.attachments.length" class="attachments-list">
								<li v-for="a in selected.attachments" :key="a.id">
									<a v-if="isPreviewable(a)" href="#" @click.prevent="openAttachment(a)">{{ a.fileName }}</a>
									<span v-else>{{ a.fileName }}</span>
									<span class="attachment-meta">{{ formatFileSize(a.size) }}</span>
									<a
										class="icon-button icon-button-group-start"
										:href="attachmentDownloadUrl(a)"
										:aria-label="t('tickets', 'Download attachment')"
										:title="t('tickets', 'Download attachment')"
										download>
										⭳
									</a>
									<button
										v-if="canManageAttachment(a)"
										type="button"
										class="icon-button"
										:aria-label="t('tickets', 'Delete attachment')"
										:title="t('tickets', 'Delete attachment')"
										@click="deleteAttachment(a)">
										✕
									</button>
								</li>
							</ul>
							<p v-else class="field-hint">
								{{ t('tickets', 'No attachments yet.') }}
							</p>
							<div
								v-if="canComment()"
								class="attachment-upload dropzone"
								:class="{ dragging: detailDragging }"
								@dragover.prevent="detailDragging = true"
								@dragleave.prevent="detailDragging = false"
								@drop.prevent="onDetailFilesDrop">
								<input type="file" multiple :accept="allowedAttachmentAccept" :disabled="attachmentUploading" @change="onDetailFilesChange">
								<p class="field-hint">
									{{ allowedAttachmentsHint }}
								</p>
								<p class="field-hint dropzone-hint">
									{{ t('tickets', 'Or drag and drop files here') }}
								</p>
								<p v-if="attachmentUploading" class="upload-status">
									<span class="spinner" aria-hidden="true"></span>
									{{ t('tickets', 'Uploading attachments…') }}
								</p>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Aperçu image : affichage direct, sans chrome de modale (juste un
			 fond sombre cliquable pour fermer) -->
		<div v-if="previewImage" class="image-preview-overlay" @click="previewImage = null">
			<img :src="previewImage.url" :alt="previewImage.fileName">
			<button type="button" class="image-preview-close" :aria-label="t('tickets', 'Close')" @click="previewImage = null">
				×
			</button>
		</div>

		<!-- Aperçu texte : mini-modale, le contenu étant récupéré via fetch
			 (l'API renvoie le fichier en inline, pas en JSON) -->
		<div v-if="previewText" class="modal-overlay" @click.self="previewText = null">
			<div class="modal modal-text-preview" role="dialog" aria-modal="true">
				<div class="modal-header">
					<h2>{{ previewText.fileName }}</h2>
					<button type="button" class="modal-close" :aria-label="t('tickets', 'Close')" @click="previewText = null">
						×
					</button>
				</div>
				<p v-if="previewText.loading">{{ t('tickets', 'Loading…') }}</p>
				<p v-else-if="previewText.error" class="attachment-preview-error">
					{{ t('tickets', 'Could not load file preview') }}
				</p>
				<pre v-else class="attachment-text-preview">{{ previewText.content }}</pre>
			</div>
		</div>

		<!-- Aperçu PDF : modale plein écran, pages rendues en <canvas> via pdf.js
			 (pas d'iframe : Nextcloud bloque l'encadrement des routes de fichier
			 brut, y compris en same-origin). Lien de secours vers l'ouverture
			 dans un nouvel onglet si le rendu échoue. -->
		<div v-if="previewPdf" class="modal-overlay" @click.self="previewPdf = null">
			<div class="modal modal-pdf-preview" role="dialog" aria-modal="true">
				<div class="modal-header">
					<h2>{{ previewPdf.fileName }}</h2>
					<div class="modal-header-actions">
						<a :href="previewPdf.url" target="_blank" rel="noopener noreferrer" class="pdf-preview-newtab">
							{{ t('tickets', 'Open in new tab') }}
						</a>
						<button type="button" class="modal-close" :aria-label="t('tickets', 'Close')" @click="previewPdf = null">
							×
						</button>
					</div>
				</div>
				<div class="pdf-preview-body">
					<p v-if="previewPdf.loading">{{ t('tickets', 'Loading…') }}</p>
					<p v-else-if="previewPdf.error" class="attachment-preview-error">
						{{ t('tickets', 'Could not load file preview') }}
					</p>
					<canvas
						v-for="p in previewPdf.pageCount"
						:key="p"
						:ref="'pdfPage' + p"
						class="pdf-preview-page" />
				</div>
			</div>
		</div>

		</template>
	</div>
</template>

<script>
import { showSuccess, showError } from '@nextcloud/dialogs'
import '@nextcloud/dialogs/styles/toast.scss'
import * as pdfjsLib from 'pdfjs-dist/legacy/build/pdf'

// pdf.js fait tourner le parsing/rendu dans un Web Worker séparé pour ne pas
// bloquer l'UI. webpack (Asset Modules, natif depuis webpack 5, pas de
// plugin nécessaire) empaquette ce fichier worker et l'expose comme une URL
// servie par l'app elle-même (même origine que le reste des assets, dans
// tickets/js/). Sans cette ligne, les versions récentes de pdf.js échouent
// à charger le document au lieu de retomber sur un mode dégradé.
pdfjsLib.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/legacy/build/pdf.worker.min.js', import.meta.url).toString()

// Libellés lisibles pour les valeurs internes (stockées en anglais côté API/BDD).
// Les catégories, elles, sont désormais configurables par l'admin : leurs libellés
// viennent de `this.categories` (voir categoryLabel ci-dessous), pas d'une table figée.
const STATUS_LABELS = {
	new: t => t('tickets', 'New'),
	in_progress: t => t('tickets', 'In progress'),
	resolved: t => t('tickets', 'Resolved'),
	closed: t => t('tickets', 'Closed'),
}
const PRIORITY_LABELS = {
	low: t => t('tickets', 'Low'),
	normal: t => t('tickets', 'Normal'),
	urgent: t => t('tickets', 'Urgent'),
}
// Icônes de priorité : la couleur seule (vert/orange/rouge) ne suffit pas à distinguer
// les niveaux pour une personne daltonienne ou malvoyante, d'où ce repère de forme en
// plus du texte, aussi bien dans les select que dans les badges du tableau/de la fiche.
const PRIORITY_ICONS = {
	low: '▽',
	normal: '●',
	urgent: '▲',
}

// Repli utilisé le temps que /api/context réponde (voir loadContext), et si un
// backend plus ancien ne renvoie pas encore allowedExtensions.
const DEFAULT_ALLOWED_ATTACHMENT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'docx', 'pdf', 'txt']
// Idem pour la taille max, en Mo (voir ConfigService::DEFAULT_MAX_ATTACHMENT_SIZE_MB côté serveur).
const DEFAULT_MAX_ATTACHMENT_SIZE_MB = 20

export default {
	name: 'App',
	data() {
		return {
			tickets: [],
			selected: null,
			isBoardMember: false,
			canRequest: true,
			statuses: [],
			priorities: [],
			categories: [],
			form: { title: '', description: '', category: '', priority: 'normal', requesterName: '', requesterLocation: '' },
			// Fichiers choisis dans le formulaire de nouvelle requête, en attente d'envoi :
			// on ne peut les téléverser qu'une fois le ticket créé (il faut son id).
			pendingFiles: [],
			pendingFileUrls: [],
			// Retour visuel (bordure en surbrillance) pendant un survol de glisser-déposer,
			// respectivement pour la zone de pièces jointes du formulaire de nouvelle
			// requête et celle de la fiche détail.
			newTicketDragging: false,
			detailDragging: false,
			// Extensions autorisées pour les pièces jointes, reçues de /api/context (voir
			// ConfigService::getAllowedExtensions côté serveur ; validation qui fait foi).
			allowedAttachmentExtensions: DEFAULT_ALLOWED_ATTACHMENT_EXTENSIONS,
			// Taille max (Mo) d'une pièce jointe, reçue de /api/context (voir
			// ConfigService::getMaxAttachmentSizeMb côté serveur ; validation qui fait foi).
			maxAttachmentSizeMb: DEFAULT_MAX_ATTACHMENT_SIZE_MB,
			// Exposé pour l'attribut `accept` des deux <input type="file"> du template.
			allowedAttachmentAccept: DEFAULT_ALLOWED_ATTACHMENT_EXTENSIONS.map(ext => '.' + ext).join(','),
			// UID de l'utilisateur courant, nécessaire pour savoir si on peut supprimer
			// une pièce jointe qu'on a soi-même déposée (voir canManageAttachment).
			uid: '',
			attachmentUploading: false,
			// Valeurs par défaut (nom complet et adresse du profil) reçues de /api/context,
			// réappliquées à chaque réinitialisation du formulaire après envoi.
			defaultRequesterName: '',
			defaultRequesterLocation: '',
			// Réglages admin appliqués côté affichage : libellé personnalisé du champ
			// "Localisation" (chaîne vide = libellé par défaut traduit) et activation
			// du champ "À traiter avant le" (voir /api/context).
			locationLabelFr: '',
			locationLabelEn: '',
			dueDateEnabled: true,
			commentText: '',
			showNewTicketModal: false,
			// Aperçu maison (remplace la dépendance à OCA.Viewer) : une image
			// s'affiche directement dans un overlay, un texte est chargé puis
			// affiché dans une mini-modale, un PDF est rendu page par page en
			// <canvas> via pdf.js. null quand aucun aperçu n'est ouvert.
			previewImage: null,
			previewText: null,
			previewPdf: null,
			// Empêche un double envoi (double-clic, Entrée répétée) et désactive les
			// boutons "Envoyer" pendant que la requête est en cours.
			submitting: false,
			// Statut/priorité persistés du ticket ouvert dans la modale : servent à
			// détecter un changement en attente (select modifié mais pas encore
			// validé) et à garder le formulaire de commentaire visible tant que
			// l'état persisté le permet, même si une nouvelle valeur est sélectionnée.
			originalStatus: null,
			originalPriority: null,
			// Pagination de la liste des tickets : page 1-indexée côté UI, convertie
			// en offset pour l'appel API.
			ticketsTotal: 0,
			currentPage: 1,
			pageSize: 12,
			// Réglage admin : ouvrir le dossier de pièces jointes (bouton du
			// tableau, app Fichiers) dans un nouvel onglet ou non. Les PDF ont
			// désormais leur propre aperçu intégré (voir previewPdf) et ne sont
			// plus concernés par ce réglage.
			openInNewTab: true,
			// Filtres actifs du tableau ; assignedUid peut valoir '_me' ou '_unassigned'
			// en plus d'un uid réel, traduits côté serveur/juste avant l'appel.
			filters: { status: '', priority: '', category: '', assignedUid: '', search: '' },
			// Timer de debounce pour la recherche texte (évite un appel API à chaque
			// frappe) ; utilisé aussi bien côté gestionnaire que côté demandeur.
			searchDebounceTimer: null,
			// Alimente les compteurs cliquables au-dessus du tableau (voir loadTickets).
			statusCounts: { all: 0 },
			sortBy: 'created_at',
			sortOrder: 'DESC',
			// Membres des groupes gestionnaires, pour peupler le sélecteur de
			// réassignation (tableau vide côté demandeur, non utilisé).
			boardMembers: [],
			// Empêche un second changement d'assignation pendant qu'un premier est
			// en cours d'envoi (le select est désactivé le temps de la requête).
			reassigning: false,
		}
	},
	async mounted() {
		await this.loadContext()
		if (!this.canRequest) {
			return
		}
		await this.loadTickets()
		document.addEventListener('keydown', this.handleKeydown)

		// Ouverture directe depuis une notification (lien ?ticket=ID)
		const targetId = new URLSearchParams(window.location.search).get('ticket')
		if (targetId) {
			await this.openTicket(parseInt(targetId, 10))
		}
	},
	beforeDestroy() {
		document.removeEventListener('keydown', this.handleKeydown)
	},
	computed: {
		// Texte affiché sous les zones de dépôt de pièces jointes : reflète la liste
		// d'extensions réellement configurée (allowedAttachmentExtensions), et non
		// une liste figée, pour rester cohérent avec les réglages admin.
		allowedAttachmentsHint() {
			return this.t('tickets', 'Allowed files: {extensions}', {
				extensions: this.allowedAttachmentExtensions.map(ext => ext.toUpperCase()).join(', '),
			})
		},
	},
	methods: {
		handleKeydown(e) {
			if (e.key !== 'Escape') return
			if (this.previewImage) { this.previewImage = null; return }
			if (this.previewText) { this.previewText = null; return }
			if (this.previewPdf) { this.previewPdf = null; return }
			if (this.showNewTicketModal) this.showNewTicketModal = false
			if (this.selected) this.selected = null
		},
		formatDateTime(unixSeconds) {
			if (!unixSeconds) return ''
			const locale = (typeof OC !== 'undefined' && OC.getLanguage) ? OC.getLanguage() : undefined
			return new Intl.DateTimeFormat(locale, {
				dateStyle: 'short',
				timeStyle: 'short',
			}).format(new Date(unixSeconds * 1000))
		},
		formatDate(unixSeconds) {
			if (!unixSeconds) return ''
			const locale = (typeof OC !== 'undefined' && OC.getLanguage) ? OC.getLanguage() : undefined
			return new Intl.DateTimeFormat(locale, { dateStyle: 'short' }).format(new Date(unixSeconds * 1000))
		},
		// Historique d'activité horodaté : fusionne commentaires et événements
		// automatiques (statut, priorité, assignation, échéance, pièces jointes)
		// du ticket ouvert en une seule chronologie, triée par date croissante.
		timelineEntries() {
			if (!this.selected) return []
			const comments = (this.selected.comments || []).map(c => ({ kind: 'comment', id: 'c' + c.id, createdAt: c.createdAt, data: c }))
			const activity = (this.selected.activity || []).map(a => ({ kind: 'activity', id: 'a' + a.id, createdAt: a.createdAt, data: a }))
			return [...comments, ...activity].sort((x, y) => x.createdAt - y.createdAt)
		},
		// Texte descriptif d'une entrée d'activité (hors commentaires, affichés
		// séparément avec leur auteur et leur message).
		activityLabel(a) {
			switch (a.type) {
				case 'created':
					return this.t('tickets', 'Request submitted')
				case 'status_changed':
					return this.t('tickets', 'Status changed from {old} to {new}', { old: this.statusLabel(a.oldValue), new: this.statusLabel(a.newValue) })
				case 'priority_changed':
					return this.t('tickets', 'Priority changed from {old} to {new}', { old: this.priorityLabel(a.oldValue), new: this.priorityLabel(a.newValue) })
				case 'assigned_changed':
					if (!a.oldValue && a.newValue) {
						return this.t('tickets', 'Assigned to {name}', { name: a.newValue })
					}
					if (a.oldValue && !a.newValue) {
						return this.t('tickets', 'Unassigned (was {name})', { name: a.oldValue })
					}
					return this.t('tickets', 'Reassigned from {old} to {new}', { old: a.oldValue, new: a.newValue })
				case 'due_changed':
					if (!a.oldValue && a.newValue) {
						return this.t('tickets', 'Due date set to {date}', { date: a.newValue })
					}
					if (a.oldValue && !a.newValue) {
						return this.t('tickets', 'Due date cleared (was {date})', { date: a.oldValue })
					}
					return this.t('tickets', 'Due date changed from {old} to {new}', { old: a.oldValue, new: a.newValue })
				case 'attachment_added':
					return this.t('tickets', 'Attachment added: {name}', { name: a.newValue })
				case 'attachment_deleted':
					return this.t('tickets', 'Attachment removed: {name}', { name: a.oldValue })
				default:
					return a.type
			}
		},
		// Valeur pour un <input type="date"> (YYYY-MM-DD en heure locale) à partir
		// d'un timestamp unix, ou chaîne vide si aucune échéance.
		toDateInputValue(unixSeconds) {
			if (!unixSeconds) return ''
			const d = new Date(unixSeconds * 1000)
			const local = new Date(d.getTime() - d.getTimezoneOffset() * 60000)
			return local.toISOString().slice(0, 10)
		},
		isOverdue(ticket) {
			if (!ticket || !ticket.dueAt) return false
			if (ticket.status === 'resolved' || ticket.status === 'closed') return false
			return ticket.dueAt * 1000 < Date.now()
		},
		isDueSoon(ticket) {
			if (!ticket || !ticket.dueAt) return false
			if (ticket.status === 'resolved' || ticket.status === 'closed') return false
			const msLeft = ticket.dueAt * 1000 - Date.now()
			return msLeft >= 0 && msLeft <= 24 * 60 * 60 * 1000
		},
		// Échéance éditée par un gestionnaire, enregistrée immédiatement au
		// changement (comme la réassignation), pas reportée à l'envoi d'un commentaire.
		async saveDueDate(value) {
			if (!this.selected || !this.isBoardMember) return
			try {
				const res = await fetch(this.url('/api/tickets/' + this.selected.id), {
					method: 'PUT',
					headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
					body: JSON.stringify({ dueAt: value }),
				})
				if (!res.ok) {
					throw new Error('due date update failed')
				}
				const updated = await res.json()
				this.selected.dueAt = updated.dueAt
				const ticket = this.tickets.find(t => t.id === this.selected.id)
				if (ticket) {
					ticket.dueAt = updated.dueAt
				}
				this.notifySuccess(this.t('tickets', 'Due date updated'))
			} catch (e) {
				this.notifyError(this.t('tickets', 'Could not update the due date'))
			}
		},
		// Catégorie pré-sélectionnée pour une nouvelle demande : "other" si elle existe
		// encore (comportement historique), sinon la première catégorie configurée.
		defaultCategory() {
			if (this.categories.some(c => c.value === 'other')) {
				return 'other'
			}
			return this.categories.length > 0 ? this.categories[0].value : ''
		},
		categoryLabel(value) {
			const category = this.categories.find(c => c.value === value)
			if (!category) {
				return value
			}
			// Chaque catégorie a désormais un libellé français et un libellé anglais
			// explicites (configurés par l'admin) : on choisit selon la langue de
			// l'interface Nextcloud, avec repli sur l'autre langue si absent.
			const locale = (typeof OC !== 'undefined' && OC.getLanguage) ? OC.getLanguage() : ''
			if (locale.startsWith('fr')) {
				return category.label_fr || category.label_en || value
			}
			return category.label_en || category.label_fr || value
		},
		statusLabel(value) {
			return (STATUS_LABELS[value] || (() => value))(this.t)
		},
		// Le badge d'activité reprend le nom du statut sur un ticket résolu/fermé
		// (ex. « Résolu »), et affiche « Nouveau » dans les autres cas.
		unreadBadgeLabel(ticket) {
			if (['resolved', 'closed'].includes(ticket.status)) {
				return this.statusLabel(ticket.status)
			}
			return this.t('tickets', 'New')
		},
		priorityLabel(value) {
			return (PRIORITY_LABELS[value] || (() => value))(this.t)
		},
		// Un ticket résolu ou fermé n'accepte plus de nouveaux commentaires.
		isTicketOpen(ticket) {
			return !['resolved', 'closed'].includes(ticket.status)
		},
		// Le formulaire de commentaire reste visible tant que l'état PERSISTÉ du
		// ticket (avant validation) le permet — une sélection de statut en attente
		// (pas encore envoyée) ne doit pas le faire disparaître avant que
		// l'utilisateur ait pu cliquer sur Envoyer.
		canComment() {
			return this.isTicketOpen({ status: this.originalStatus })
		},
		url(path) {
			return OC.generateUrl('/apps/tickets' + path)
		},
		// Libellé du champ "Localisation" à afficher : un libellé par langue,
		// personnalisé par l'admin (ex. "Appartement" / "Apartment"), comme pour
		// les catégories (voir categoryLabel) — on choisit selon la langue de
		// l'interface Nextcloud, avec repli sur l'autre langue puis sur le
		// libellé traduit par défaut.
		locationFieldLabel() {
			const locale = (typeof OC !== 'undefined' && OC.getLanguage) ? OC.getLanguage() : ''
			if (locale.startsWith('fr')) {
				return this.locationLabelFr || this.locationLabelEn || this.t('tickets', 'Location')
			}
			return this.locationLabelEn || this.locationLabelFr || this.t('tickets', 'Location')
		},
		// Toasts en bas de l'écran via @nextcloud/dialogs (le composant officiel
		// utilisé dans toute l'interface Nextcloud), plutôt que le système maison
		// précédent — voir docs/HOWTO-nextcloud-dialogs.md pour le contexte.
		notifyError(message) {
			showError(message)
		},
		notifySuccess(message) {
			showSuccess(message)
		},
		async loadContext() {
			const res = await fetch(this.url('/api/context'), { headers: { requesttoken: OC.requestToken } })
			const data = await res.json()
			this.isBoardMember = data.isBoardMember
			this.canRequest = data.canRequest
			this.uid = data.uid
			this.statuses = data.statuses || []
			this.priorities = data.priorities || []
			this.categories = data.categories || []
			if (this.isBoardMember && (this.statuses.length === 0 || this.priorities.length === 0)) {
				// Le compte est bien reconnu gestionnaire (les listes Statut/Priorité
				// devraient donc être modifiables), mais le serveur n'a renvoyé aucune
				// valeur pour l'une des deux. Le plus souvent : le backend déployé est une
				// version plus ancienne que ce fichier JS (endpoint /api/context qui ne
				// renvoyait pas encore ces deux listes). On log pour pouvoir le confirmer
				// facilement dans la console plutôt que de deviner.
				// eslint-disable-next-line no-console
				console.error('[tickets] /api/context did not return statuses/priorities:', data)
			}
			this.defaultRequesterName = data.defaultRequesterName || ''
			this.defaultRequesterLocation = data.defaultRequesterLocation || ''
			this.locationLabelFr = data.locationLabelFr || ''
			this.locationLabelEn = data.locationLabelEn || ''
			this.dueDateEnabled = data.dueDateEnabled !== undefined ? !!data.dueDateEnabled : true
			this.openInNewTab = data.openInNewTab !== undefined ? !!data.openInNewTab : true
			if (Array.isArray(data.allowedExtensions) && data.allowedExtensions.length > 0) {
				this.allowedAttachmentExtensions = data.allowedExtensions
				this.allowedAttachmentAccept = data.allowedExtensions.map(ext => '.' + ext).join(',')
			}
			if (data.maxAttachmentSizeMb) {
				this.maxAttachmentSizeMb = data.maxAttachmentSizeMb
			}
			this.boardMembers = data.boardMembers || []
			this.form.category = this.defaultCategory()
			this.form.priority = 'normal'
			this.form.requesterName = this.defaultRequesterName
			this.form.requesterLocation = this.defaultRequesterLocation
		},
		totalPages() {
			return Math.max(1, Math.ceil(this.ticketsTotal / this.pageSize))
		},
		async loadTickets() {
			const offset = (this.currentPage - 1) * this.pageSize
			const params = new URLSearchParams()
			params.set('limit', this.pageSize)
			params.set('offset', offset)
			params.set('sort', this.sortBy)
			params.set('order', this.sortOrder)
			if (this.filters.status) params.set('status', this.filters.status)
			if (this.filters.priority) params.set('priority', this.filters.priority)
			if (this.filters.category) params.set('category', this.filters.category)
			if (this.filters.search) params.set('search', this.filters.search)
			if (this.isBoardMember && this.filters.assignedUid) {
				// '_me' est un raccourci purement côté client : le serveur ne connaît
				// que de vrais uid (ou '_unassigned'), donc on le résout ici.
				const assignedUid = this.filters.assignedUid === '_me' ? this.uid : this.filters.assignedUid
				params.set('assignedUid', assignedUid)
			}
			const res = await fetch(
				this.url('/api/tickets') + '?' + params.toString(),
				{ headers: { requesttoken: OC.requestToken } }
			)
			const data = await res.json()
			this.tickets = data.items
			this.ticketsTotal = data.total
			this.statusCounts = data.statusCounts || { all: 0 }
		},
		// Appelé au changement d'un des selects de filtre : on revient à la page 1
		// (le nombre total de résultats a changé, l'offset courant n'a plus de sens).
		async applyFilters() {
			this.currentPage = 1
			await this.loadTickets()
		},
		// Saisie dans le champ de recherche : on attend une courte pause dans la
		// frappe avant de relancer la requête, pour éviter un appel API par
		// caractère (comme applyFilters, revient à la page 1).
		onSearchInput() {
			clearTimeout(this.searchDebounceTimer)
			this.searchDebounceTimer = setTimeout(() => {
				this.applyFilters()
			}, 350)
		},
		// Clic sur un compteur de statut (bandeau au-dessus du tableau) : bascule le
		// filtre de statut sur la valeur cliquée ('' pour "Tous"), ou le retire si on
		// reclique sur le statut déjà actif.
		async selectStatusFilter(status) {
			this.filters.status = this.filters.status === status ? '' : status
			this.currentPage = 1
			await this.loadTickets()
		},
		priorityIcon(priority) {
			return PRIORITY_ICONS[priority] || ''
		},
		// URL de l'export Excel de la vue actuelle (mêmes filtres/tri que le tableau),
		// réservé au groupe gestionnaire côté serveur (TicketController::exportTickets).
		exportUrl() {
			const params = new URLSearchParams()
			if (this.filters.status) params.set('status', this.filters.status)
			if (this.filters.priority) params.set('priority', this.filters.priority)
			if (this.filters.category) params.set('category', this.filters.category)
			if (this.filters.search) params.set('search', this.filters.search)
			if (this.filters.assignedUid) {
				const assignedUid = this.filters.assignedUid === '_me' ? this.uid : this.filters.assignedUid
				params.set('assignedUid', assignedUid)
			}
			params.set('sort', this.sortBy)
			params.set('order', this.sortOrder)
			return this.url('/api/tickets/export') + '?' + params.toString()
		},
		// Suppression définitive d'un ticket (groupe gestionnaire uniquement, voir
		// TicketController::destroy) : confirmation obligatoire, l'action n'est pas
		// annulable côté serveur (pièces jointes et commentaires supprimés avec).
		async deleteTicket() {
			if (!this.selected || !this.isBoardMember) return
			const confirmed = window.confirm(
				this.t('tickets', 'Delete this ticket permanently? Comments and attachments will be lost. This cannot be undone.')
			)
			if (!confirmed) return

			const id = this.selected.id
			try {
				const res = await fetch(this.url('/api/tickets/' + id), {
					method: 'DELETE',
					headers: { requesttoken: OC.requestToken },
				})
				if (!res.ok) {
					throw new Error('delete failed')
				}
				this.selected = null
				this.tickets = this.tickets.filter(t => t.id !== id)
				this.ticketsTotal = Math.max(0, this.ticketsTotal - 1)
				this.notifySuccess(this.t('tickets', 'Ticket deleted'))
				await this.loadTickets()
			} catch (e) {
				this.notifyError(this.t('tickets', 'Could not delete the ticket'))
			}
		},
		// Changement de statut dans la fiche détail (gestionnaire) : le select n'est
		// PAS lié en v-model afin de pouvoir annuler la sélection si le passage à
		// "Fermé" n'est pas confirmé (le navigateur a déjà changé l'affichage natif du
		// <select>, mais tant que selected.status ne bouge pas, le :value le refait
		// pointer sur l'ancienne valeur au prochain rendu). Comme pour priorité, ce
		// changement n'est appliqué côté serveur qu'à l'envoi du commentaire (voir
		// submitComment/buildAutoMessage) — seule la confirmation est immédiate ici.
		onStatusSelectChange(newStatus) {
			if (newStatus === 'closed' && this.selected.status !== 'closed') {
				const confirmed = window.confirm(
					this.t('tickets', 'Close this ticket? Comments and attachments will be locked.')
				)
				if (!confirmed) {
					return
				}
			}
			this.selected.status = newStatus
		},
		// Édition du nom/localisation du demandeur par un gestionnaire, enregistrée
		// immédiatement à la perte de focus (comme la réassignation) plutôt que reportée
		// à l'envoi d'un commentaire : ce n'est pas une décision liée à un message.
		async saveRequesterInfo() {
			if (!this.selected || !this.isBoardMember) return
			const name = this.selected.requesterName
			const location = this.selected.requesterLocation
			if (name === this.originalRequesterName && location === this.originalRequesterLocation) {
				return
			}
			try {
				const res = await fetch(this.url('/api/tickets/' + this.selected.id), {
					method: 'PUT',
					headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
					body: JSON.stringify({ requesterName: name, requesterLocation: location }),
				})
				if (!res.ok) {
					throw new Error('requester info update failed')
				}
				const updated = await res.json()
				this.selected.requesterName = updated.requesterName
				this.selected.requesterLocation = updated.requesterLocation
				this.originalRequesterName = updated.requesterName
				this.originalRequesterLocation = updated.requesterLocation
				const ticket = this.tickets.find(t => t.id === this.selected.id)
				if (ticket) {
					ticket.requesterName = updated.requesterName
					ticket.requesterLocation = updated.requesterLocation
				}
				this.notifySuccess(this.t('tickets', 'Requester info updated'))
			} catch (e) {
				this.selected.requesterName = this.originalRequesterName
				this.selected.requesterLocation = this.originalRequesterLocation
				this.notifyError(this.t('tickets', 'Could not update requester info'))
			}
		},
		hasActiveFilters() {
			return !!(this.filters.status || this.filters.priority || this.filters.category || this.filters.assignedUid || this.filters.search)
		},
		async resetFilters() {
			clearTimeout(this.searchDebounceTimer)
			this.filters = { status: '', priority: '', category: '', assignedUid: '', search: '' }
			this.currentPage = 1
			await this.loadTickets()
		},
		// Clic sur un en-tête de colonne triable : inverse l'ordre si on reclique sur
		// la même colonne, repart en ascendant sur une nouvelle colonne.
		async toggleSort(column) {
			if (this.sortBy === column) {
				this.sortOrder = this.sortOrder === 'ASC' ? 'DESC' : 'ASC'
			} else {
				this.sortBy = column
				this.sortOrder = 'ASC'
			}
			this.currentPage = 1
			await this.loadTickets()
		},
		// Icône affichée en permanence sur les colonnes triables : flèche double
		// discrète tant que la colonne n'est pas le tri actif, remplacée par une
		// flèche simple (bien visible) une fois qu'elle l'est.
		sortIndicator(column) {
			if (this.sortBy !== column) return '⇅'
			return this.sortOrder === 'ASC' ? '▲' : '▼'
		},
		sortIndicatorActive(column) {
			return this.sortBy === column
		},
		// Réassignation immédiate depuis la modale (indépendante du statut/priorité,
		// qui restent en attente jusqu'à l'envoi d'un commentaire) : on envoie tout
		// de suite, sans passer par le champ de message.
		async changeAssignee(newUid) {
			if (!this.selected || this.reassigning) return
			const previousUid = this.selected.assignedUid || ''
			if (newUid === previousUid) return

			await this.sendAssigneeChange(newUid, previousUid)
		},
		// Envoie la réassignation en indiquant au serveur l'assignation que le client
		// croit actuelle (expectedAssignedUid). Si un autre gestionnaire a réassigné le
		// ticket entre-temps (ex. la fiche était restée ouverte), le serveur répond 409
		// au lieu d'écraser silencieusement ce changement : on informe l'utilisateur et
		// on ne réémet la requête que s'il confirme vouloir réassigner quand même.
		async sendAssigneeChange(newUid, expectedUid) {
			this.reassigning = true
			try {
				const res = await fetch(this.url('/api/tickets/' + this.selected.id), {
					method: 'PUT',
					headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
					body: JSON.stringify({ assignedUid: newUid, expectedAssignedUid: expectedUid }),
				})
				if (res.status === 409) {
					const conflict = await res.json()
					const currentName = conflict.assignedDisplayName || this.t('tickets', 'Unassigned')
					const confirmed = window.confirm(
						this.t('tickets', 'This ticket has since been assigned to {name}. Reassign it anyway?', { name: currentName })
					)
					if (confirmed) {
						await this.sendAssigneeChange(newUid, conflict.assignedUid || '')
					}
					return
				}
				if (!res.ok) {
					throw new Error('assignee update failed')
				}
				const updated = await res.json()
				this.selected.assignedUid = updated.assignedUid
				const member = this.boardMembers.find(m => m.uid === updated.assignedUid)
				this.selected.assignedDisplayName = member ? member.displayName : (updated.assignedUid || null)
				// Patch la ligne du tableau en place, sans recharger toute la liste.
				const ticket = this.tickets.find(t => t.id === this.selected.id)
				if (ticket) {
					ticket.assignedUid = this.selected.assignedUid
					ticket.assignedDisplayName = this.selected.assignedDisplayName
				}
				this.notifySuccess(this.t('tickets', 'Ticket reassigned'))
			} catch (e) {
				this.notifyError(this.t('tickets', 'Could not reassign the ticket'))
			} finally {
				this.reassigning = false
			}
		},
		async goToPage(page) {
			const clamped = Math.min(Math.max(1, page), this.totalPages())
			if (clamped === this.currentPage) return
			this.currentPage = clamped
			await this.loadTickets()
		},
		async createTicket() {
			if (this.submitting) return
			this.submitting = true
			try {
				await this.submitNewTicket(false)
			} catch (e) {
				this.notifyError(this.t('tickets', 'Could not submit the request'))
			} finally {
				this.submitting = false
			}
		},
		// Sépare l'envoi effectif de la garde anti-double-clic (this.submitting) : le
		// renvoi après confirmation d'un doublon potentiel (force=true) doit pouvoir
		// s'exécuter alors que this.submitting est déjà à true depuis le premier appel.
		async submitNewTicket(force) {
			const res = await fetch(this.url('/api/tickets'), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
				body: JSON.stringify({ ...this.form, force }),
			})
			if (res.status === 409) {
				const body = await res.json().catch(() => ({}))
				const duplicates = body.duplicates || []
				if (duplicates.length) {
					const list = duplicates.map(d => `${d.ticketNumber} — ${d.title}`).join('\n')
					const confirmed = window.confirm(
						this.t('tickets', 'This looks similar to a request you already have open:\n{list}\n\nSubmit it anyway?', { list })
					)
					if (confirmed) {
						await this.submitNewTicket(true)
					}
				}
				return
			}
			const body = await res.json().catch(() => ({}))
			if (!res.ok) {
				throw new Error(body.message || 'Error')
			}
			// On recharge la liste (au lieu de patcher this.tickets/ticketsTotal à la
			// main) : ça remet aussi à jour les compteurs par statut au-dessus du
			// tableau (statusCounts), qui sinon restaient figés jusqu'au prochain
			// changement de filtre/page — un patch local du total ne les touchait pas.
			await this.loadTickets()
			// Les pièces jointes ne peuvent être envoyées qu'une fois le ticket créé
			// (il faut son id) : on les poste juste après, une par une. Un échec ici
			// ne remet pas en cause la création du ticket, déjà actée côté serveur.
			if (this.pendingFiles.length) {
				this.attachmentUploading = true
				try {
					await this.uploadFiles(body.id, this.pendingFiles)
				} catch (e) {
					this.notifyError(this.t('tickets', 'Ticket created, but some attachments could not be uploaded'))
				} finally {
					this.attachmentUploading = false
				}
			}
			this.revokePendingFileUrls()
			this.pendingFiles = []
			this.form = {
				title: '',
				description: '',
				category: this.defaultCategory(),
				priority: 'normal',
				requesterName: this.defaultRequesterName,
				requesterLocation: this.defaultRequesterLocation,
			}
			this.showNewTicketModal = false
			this.notifySuccess(this.t('tickets', 'Ticket sent'))
		},
		async openTicket(id) {
			const res = await fetch(this.url('/api/tickets/' + id), { headers: { requesttoken: OC.requestToken } })
			this.selected = await res.json()
			this.originalStatus = this.selected.status
			this.originalPriority = this.selected.priority
			this.originalRequesterName = this.selected.requesterName
			this.originalRequesterLocation = this.selected.requesterLocation
			// Retour visuel immédiat : le serveur a déjà marqué le ticket comme lu,
			// pas besoin d'attendre le prochain loadTickets() pour effacer la pastille.
			const ticket = this.tickets.find(t => t.id === id)
			if (ticket) {
				ticket.hasUnread = false
			}
		},
		// Ouvre une URL selon le réglage admin "ouvrir dans un nouvel onglet"
		// (dossier de pièces jointes) : nouvel onglet ou onglet courant.
		openUrl(url) {
			if (this.openInNewTab) {
				window.open(url, '_blank', 'noopener,noreferrer')
			} else {
				window.location.href = url
			}
		},
		// Depuis le tableau : ouvre le dossier de pièces jointes du ticket dans
		// l'app Fichiers (colonne "Attachments" du tableau côté gestionnaire),
		// dans un nouvel onglet ou l'onglet courant selon le réglage admin.
		async openTicketAttachments(id) {
			try {
				const res = await fetch(this.url('/api/tickets/' + id + '/attachments-folder'), { headers: { requesttoken: OC.requestToken } })
				if (!res.ok) {
					throw new Error('folder link request failed')
				}
				const data = await res.json()
				this.openUrl(data.url)
			} catch (e) {
				this.notifyError(this.t('tickets', 'Could not open the attachments folder'))
			}
		},
		// Construit un message automatique décrivant le changement d'état/priorité
		// quand l'utilisateur valide avec un champ de commentaire vide.
		buildAutoMessage(statusChanged, priorityChanged) {
			const parts = []
			if (statusChanged) {
				parts.push(this.t('tickets', 'Status changed to {status}', { status: this.statusLabel(this.selected.status) }))
			}
			if (priorityChanged) {
				parts.push(this.t('tickets', 'Priority changed to {priority}', { priority: this.priorityLabel(this.selected.priority) }))
			}
			return parts.join(' — ')
		},
		// Seul le bouton Envoyer valide quoi que ce soit : le commentaire, et — s'ils
		// ont été modifiés dans les select — le statut et la priorité. Tout part dans
		// une seule requête (POST /comments) : le backend applique le changement
		// d'état/priorité avant de construire la notification du commentaire, pour
		// que celle-ci affiche le statut réellement à jour (pas l'ancien).
		async submitComment() {
			if (!this.selected || !this.canComment()) return
			if (this.submitting) return

			const statusChanged = this.isBoardMember && this.selected.status !== this.originalStatus
			const priorityChanged = this.isBoardMember && this.selected.priority !== this.originalPriority
			const trimmed = this.commentText.trim()

			if (!trimmed && !statusChanged && !priorityChanged) return

			const message = trimmed || this.buildAutoMessage(statusChanged, priorityChanged)
			const payload = { message }
			if (statusChanged) payload.status = this.selected.status
			if (priorityChanged) payload.priority = this.selected.priority

			this.submitting = true
			try {
				const res = await fetch(this.url('/api/tickets/' + this.selected.id + '/comments'), {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
					body: JSON.stringify(payload),
				})
				if (!res.ok) {
					const body = await res.json().catch(() => ({}))
					throw new Error(body.message || 'Error')
				}

				this.commentText = ''
				// On ferme la modale et on recharge la liste : le statut peut avoir changé
				// côté serveur sans que le select ait été touché (ex. passage automatique
				// à "En cours" sur un ticket "Nouveau"), donc this.selected.status n'est pas
				// fiable pour patcher la ligne localement — mieux vaut refléter l'état réel.
				this.selected = null
				await this.loadTickets()
				this.notifySuccess(this.t('tickets', 'Message sent'))
			} catch (e) {
				this.notifyError(this.t('tickets', 'Could not submit the comment'))
			} finally {
				this.submitting = false
			}
		},
		// Extension du fichier présente dans allowedAttachmentExtensions (juste un
		// confort d'usage côté client — la validation qui fait foi est côté serveur).
		isAllowedAttachment(file) {
			const dot = file.name.lastIndexOf('.')
			if (dot === -1) return false
			const ext = file.name.slice(dot + 1).toLowerCase()
			return this.allowedAttachmentExtensions.includes(ext)
		},
		// Taille du fichier sous la limite configurée (même remarque : confort client,
		// la validation qui fait foi est côté serveur, voir AttachmentService).
		isAllowedAttachmentSize(file) {
			return file.size <= this.maxAttachmentSizeMb * 1024 * 1024
		},
		// Sépare les fichiers valides des fichiers rejetés (type non autorisé ou trop
		// volumineux) et prévient l'utilisateur avec un message précis pour chaque
		// motif de rejet, avant de renvoyer la liste des fichiers valides.
		filterAllowedAttachments(files) {
			const tooBig = files.filter(f => this.isAllowedAttachment(f) && !this.isAllowedAttachmentSize(f))
			const wrongType = files.filter(f => !this.isAllowedAttachment(f))
			const allowed = files.filter(f => this.isAllowedAttachment(f) && this.isAllowedAttachmentSize(f))
			if (wrongType.length > 0) {
				this.notifyError(this.t('tickets', 'Some files were ignored (allowed types: {types})', { types: this.allowedAttachmentExtensions.join(', ').toUpperCase() }))
			}
			if (tooBig.length > 0) {
				this.notifyError(this.t('tickets', 'Some files were ignored (maximum size: {size} MB)', { size: this.maxAttachmentSizeMb }))
			}
			return allowed
		},
		onNewTicketFilesChange(e) {
			this.addPendingFiles(Array.from(e.target.files || []))
			// Réinitialise l'input pour permettre de resélectionner le même fichier
			// et pour que chaque ouverture du sélecteur vienne s'ajouter à la liste
			// plutôt que remplacer sa valeur précédente.
			e.target.value = ''
		},
		onNewTicketFilesDrop(e) {
			this.newTicketDragging = false
			this.addPendingFiles(Array.from(e.dataTransfer.files || []))
		},
		// Ajoute des fichiers à la sélection en attente (au lieu de la remplacer),
		// pour permettre d'ajouter plusieurs pièces jointes en plusieurs fois
		// (sélecteur de fichiers rouvert ou glisser-déposer successifs).
		addPendingFiles(files) {
			const allowed = this.filterAllowedAttachments(files)
			if (!allowed.length) return
			this.pendingFiles = [...this.pendingFiles, ...allowed]
			this.pendingFileUrls = [...this.pendingFileUrls, ...allowed.map(f => URL.createObjectURL(f))]
		},
		revokePendingFileUrls() {
			(this.pendingFileUrls || []).forEach(u => URL.revokeObjectURL(u))
			this.pendingFileUrls = []
		},
		pendingFileUrl(file) {
			const index = this.pendingFiles.indexOf(file)
			return this.pendingFileUrls[index]
		},
		removePendingFile(index) {
			if (this.pendingFileUrls[index]) {
				URL.revokeObjectURL(this.pendingFileUrls[index])
			}
			this.pendingFiles = this.pendingFiles.filter((_, i) => i !== index)
			this.pendingFileUrls = this.pendingFileUrls.filter((_, i) => i !== index)
		},
		// Poste les fichiers un par un (l'API accepte un seul fichier par requête,
		// comme l'import de catégories côté admin). Si le ticket concerné est celui
		// actuellement ouvert dans la modale de détail, on ajoute chaque pièce jointe
		// au fur et à mesure plutôt que de recharger tout le ticket.
		async uploadFiles(ticketId, files) {
			for (const file of files) {
				const formData = new FormData()
				formData.append('file', file)
				const res = await fetch(this.url('/api/tickets/' + ticketId + '/attachments'), {
					method: 'POST',
					headers: { requesttoken: OC.requestToken },
					body: formData,
				})
				if (!res.ok) {
					const body = await res.json().catch(() => ({}))
					throw new Error(body.message || 'Error')
				}
				const attachment = await res.json()
				if (this.selected && this.selected.id === ticketId) {
					this.selected.attachments.push(attachment)
				}
			}
		},
		async onDetailFilesChange(e) {
			const files = this.filterAllowedAttachments(Array.from(e.target.files || []))
			e.target.value = ''
			await this.addDetailFiles(files)
		},
		// Même chemin de dépôt que le <input type="file">, déclenché par un glisser-
		// déposer sur la zone de pièces jointes de la fiche détail (voir aussi
		// onNewTicketFilesDrop pour le formulaire de nouvelle requête).
		async onDetailFilesDrop(e) {
			this.detailDragging = false
			const files = this.filterAllowedAttachments(Array.from(e.dataTransfer.files || []))
			await this.addDetailFiles(files)
		},
		async addDetailFiles(files) {
			if (!files.length || !this.selected) return

			this.attachmentUploading = true
			try {
				await this.uploadFiles(this.selected.id, files)
			} catch (e) {
				this.notifyError(this.t('tickets', 'Could not upload attachment'))
			} finally {
				this.attachmentUploading = false
			}
		},
		// Le groupe gestionnaire peut tout supprimer ; un demandeur ne peut retirer
		// que ses propres dépôts, et seulement tant que le ticket est encore ouvert
		// (même règle que canComment côté commentaires) — cohérent avec les droits
		// vérifiés côté serveur dans TicketController::deleteAttachment.
		canManageAttachment(attachment) {
			return this.isBoardMember || (attachment.uploadedBy === this.uid && this.canComment())
		},
		// URL de téléchargement "classique" (Content-Disposition: attachment),
		// utilisée par le bouton de téléchargement et pour le docx (non
		// prévisualisable ; le PDF a son propre aperçu intégré, voir
		// isPdfAttachment/openAttachment).
		attachmentDownloadUrl(attachment) {
			return this.url('/api/tickets/' + this.selected.id + '/attachments/' + attachment.id)
		},
		// Même route, mais avec inline=1 : c'est celle que consomme l'aperçu
		// maison (image affichée directement, texte récupéré via fetch).
		attachmentViewUrl(attachment) {
			return this.url('/api/tickets/' + this.selected.id + '/attachments/' + attachment.id + '?inline=1')
		},
		// Une image se reconnaît d'abord à son mimetype ; l'extension sert de
		// repli si le mimetype est absent ou générique (octet-stream).
		isImageAttachment(attachment) {
			if (attachment.mimetype && attachment.mimetype.startsWith('image/')) return true
			return /\.(jpe?g|png)$/i.test(attachment.fileName || '')
		},
		isTextAttachment(attachment) {
			if (attachment.mimetype === 'text/plain') return true
			return /\.txt$/i.test(attachment.fileName || '')
		},
		isPdfAttachment(attachment) {
			if (attachment.mimetype === 'application/pdf') return true
			return /\.pdf$/i.test(attachment.fileName || '')
		},
		// Types qu'on sait afficher nous-mêmes dans l'app (image, texte, PDF)
		// sont considérés "prévisualisables" au sens de l'aperçu maison.
		isPreviewable(attachment) {
			return this.isImageAttachment(attachment) || this.isTextAttachment(attachment) || this.isPdfAttachment(attachment)
		},
		// Aperçu maison : une image s'affiche directement (juste un <img> dans
		// un overlay), un texte est chargé puis affiché dans une mini-modale,
		// un PDF est rendu page par page en <canvas> via pdf.js (voir
		// openPdfPreview) : reste dans l'app, pas d'onglet, pas d'iframe.
		openAttachment(attachment) {
			if (this.isImageAttachment(attachment)) {
				this.previewImage = { url: this.attachmentViewUrl(attachment), fileName: attachment.fileName }
				return
			}
			if (this.isPdfAttachment(attachment)) {
				this.openPdfPreview(attachment)
				return
			}
			if (this.isTextAttachment(attachment)) {
				this.previewText = { fileName: attachment.fileName, content: '', loading: true, error: false }
				this.loadTextPreview(attachment)
			}
		},
		// Charge le PDF via pdf.js (pas de <iframe> : Nextcloud renvoie un
		// en-tête anti-cadrage sur les routes de fichier brut, même en
		// same-origin, pour empêcher qu'un fichier déposé par un utilisateur
		// soit encadré) et rend chaque page dans un <canvas> ajouté au DOM
		// par le v-for du template, une fois previewPdf.pageCount connu.
		async openPdfPreview(attachment) {
			const url = this.attachmentViewUrl(attachment)
			this.previewPdf = { url, fileName: attachment.fileName, loading: true, error: false, pageCount: 0 }
			try {
				const pdf = await pdfjsLib.getDocument({ url, withCredentials: true }).promise
				if (!this.previewPdf) return // fermé pendant le chargement
				this.previewPdf.pageCount = pdf.numPages
				this.previewPdf.loading = false
				await this.$nextTick()
				for (let i = 1; i <= pdf.numPages; i++) {
					if (!this.previewPdf) return // fermé pendant le rendu
					const canvasRef = this.$refs['pdfPage' + i]
					const canvas = Array.isArray(canvasRef) ? canvasRef[0] : canvasRef
					if (!canvas) continue
					const page = await pdf.getPage(i)
					const viewport = page.getViewport({ scale: 1.3 })
					canvas.width = viewport.width
					canvas.height = viewport.height
					await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise
				}
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[tickets] PDF preview failed:', e)
				if (this.previewPdf) {
					this.previewPdf.loading = false
					this.previewPdf.error = true
				}
			}
		},
		// Le contenu texte n'est pas exposé en JSON par l'API : on récupère la
		// même route "inline" que pour l'image, mais en tant que texte brut.
		async loadTextPreview(attachment) {
			try {
				const res = await fetch(this.attachmentViewUrl(attachment), { headers: { requesttoken: OC.requestToken } })
				if (!res.ok) throw new Error('Error')
				const text = await res.text()
				if (this.previewText) {
					this.previewText.content = text
					this.previewText.loading = false
				}
			} catch (e) {
				if (this.previewText) {
					this.previewText.error = true
					this.previewText.loading = false
				}
			}
		},
		async deleteAttachment(attachment) {
			if (!this.selected) return
			try {
				const res = await fetch(this.url('/api/tickets/' + this.selected.id + '/attachments/' + attachment.id), {
					method: 'DELETE',
					headers: { requesttoken: OC.requestToken },
				})
				if (!res.ok) {
					const body = await res.json().catch(() => ({}))
					throw new Error(body.message || 'Error')
				}
				this.selected.attachments = this.selected.attachments.filter(a => a.id !== attachment.id)
			} catch (e) {
				this.notifyError(this.t('tickets', 'Could not delete attachment'))
			}
		},
		formatFileSize(bytes) {
			if (!bytes) return '0 B'
			const units = ['B', 'KB', 'MB', 'GB']
			let value = bytes
			let unitIndex = 0
			while (value >= 1024 && unitIndex < units.length - 1) {
				value /= 1024
				unitIndex++
			}
			return (unitIndex === 0 ? value : value.toFixed(1)) + ' ' + units[unitIndex]
		},
	},
}
</script>

<style scoped>
.tickets-app {
	width: 100%;
	min-height: 100%;
	box-sizing: border-box;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}
.tickets-app > section {
	padding: 0 32px;
}
.tickets-app > section:first-of-type { padding-top: 24px; }
.access-denied {
	color: var(--color-text-maxcontrast, #767676);
}
/* Titre de la liste et bouton "+ Nouvelle requête" sur la même ligne,
   pour économiser la hauteur autrefois prise par le <h1> séparé et la
   section dédiée au bouton (cf. maquette de référence). */
.ticket-list-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	flex-wrap: wrap;
}
.ticket-list-header h2 { margin: 0; }
.ticket-list-header button.primary {
	padding: 10px 22px;
	border: none;
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	font-weight: 600;
	font-size: 1em;
	cursor: pointer;
}
.ticket-list-header button.primary:hover { background-color: var(--color-primary-element-hover, #006aa3); }

.modal-overlay {
	position: fixed;
	inset: 0;
	background-color: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10000;
}
.modal {
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	border-radius: var(--border-radius-large, 8px);
	box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
	width: 100%;
	max-width: 560px;
	max-height: 90vh;
	overflow-y: auto;
	padding: 28px 32px 32px;
	box-sizing: border-box;
}
.modal-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 16px;
}
.modal-header h2 { margin: 0; }
.modal-close {
	background: none;
	border: none;
	font-size: 1.6em;
	line-height: 1;
	cursor: pointer;
	color: var(--color-text-maxcontrast, #767676);
	padding: 4px 8px;
}
.modal-close:hover { color: var(--color-main-text); }

.modal-detail { max-width: 860px; }

/* Aperçu image : overlay plein écran, l'image occupe l'essentiel de l'espace
   sans autre chrome qu'un bouton de fermeture flottant. */
.image-preview-overlay {
	position: fixed;
	inset: 0;
	background-color: rgba(0, 0, 0, 0.85);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10001;
}
.image-preview-overlay img {
	max-width: 90vw;
	max-height: 90vh;
	object-fit: contain;
	box-shadow: 0 4px 24px rgba(0, 0, 0, 0.5);
}
.image-preview-close {
	position: absolute;
	top: 16px;
	right: 24px;
	background: none;
	border: none;
	font-size: 2em;
	line-height: 1;
	cursor: pointer;
	color: #fff;
	padding: 4px 8px;
}
.image-preview-close:hover { color: var(--color-primary-element, #00679e); }

/* Aperçu texte : petite modale, contenu en police monospace et défilable. */
.modal-text-preview { max-width: 640px; }
.attachment-text-preview {
	white-space: pre-wrap;
	word-break: break-word;
	max-height: 60vh;
	overflow-y: auto;
	margin: 0;
	padding: 12px;
	background-color: var(--color-background-hover, #f5f5f5);
	border-radius: var(--border-radius, 4px);
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.9em;
}
.attachment-preview-error { color: var(--color-error, #c9302c); }

/* Aperçu PDF : modale large occupant l'essentiel de l'écran, l'iframe
   remplit l'espace disponible en dessous de l'en-tête. */
.modal-pdf-preview {
	max-width: 96vw;
	width: 1000px;
	height: 92vh;
	display: flex;
	flex-direction: column;
	padding: 16px 16px 20px;
}
.modal-pdf-preview .modal-header { margin-bottom: 12px; flex: 0 0 auto; }
.modal-header-actions {
	display: flex;
	align-items: center;
	gap: 4px;
}
.pdf-preview-newtab {
	font-size: 0.9em;
	color: var(--color-primary-element, #00679e);
	white-space: nowrap;
	padding: 4px 8px;
}
.pdf-preview-newtab:hover { text-decoration: underline; }
.pdf-preview-body {
	flex: 1 1 auto;
	overflow-y: auto;
	border-radius: var(--border-radius, 4px);
	background-color: #525659;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 16px;
	padding: 16px;
}
.pdf-preview-body p {
	color: #fff;
	margin: auto;
}
.pdf-preview-page {
	max-width: 100%;
	box-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
}

.modal-columns {
	display: flex;
	gap: 32px;
	margin-top: 4px;
}
.modal-col { min-width: 0; }
.modal-col-left { flex: 1.3 1 0; }
.modal-col-right {
	flex: 1 1 0;
	border-left: 1px solid var(--color-border, #e0e0e0);
	padding-left: 24px;
}
@media (max-width: 680px) {
	.modal-columns { flex-direction: column; gap: 20px; }
	.modal-col-right {
		border-left: none;
		border-top: 1px solid var(--color-border, #e0e0e0);
		padding-left: 0;
		padding-top: 16px;
	}
}

.modal .field {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
.modal .field-row {
	display: flex;
	gap: 20px;
	flex-wrap: wrap;
}
.modal .field-inline {
	flex: 1 1 160px;
	min-width: 160px;
}
.modal .field label {
	font-weight: 600;
	font-size: 0.9em;
}
.modal input[type="text"],
.modal textarea,
.modal select {
	width: 100%;
	box-sizing: border-box;
	padding: 8px 10px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 1em;
}
.modal select {
	color-scheme: light dark;
	-webkit-appearance: none;
	appearance: none;
}
.modal textarea { resize: vertical; }

.new-ticket-form {
	display: flex;
	flex-direction: column;
	gap: 20px;
	margin-top: 4px;
}
.form-actions { margin-top: 8px; }
.form-actions button.primary {
	padding: 10px 22px;
	border: none;
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	font-weight: 600;
	cursor: pointer;
}
.form-actions button.primary:hover { background-color: var(--color-primary-element-hover, #006aa3); }
.form-actions {
	display: flex;
	align-items: center;
	gap: 12px;
}

/* Spinner générique (envoi / téléversement de pièces jointes) : durée
   indéterminée, donc pas de barre de progression, juste une indication
   visuelle que quelque chose est en cours. */
.spinner {
	display: inline-block;
	width: 16px;
	height: 16px;
	border: 2px solid var(--color-border-dark, #ccc);
	border-top-color: var(--color-primary-element, #0082c9);
	border-radius: 50%;
	animation: tickets-spin 0.8s linear infinite;
	vertical-align: middle;
}
@keyframes tickets-spin {
	to { transform: rotate(360deg); }
}
.upload-status {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	font-size: 0.9em;
	font-weight: 600;
	color: var(--color-primary-element, #0082c9);
	margin: 8px 0 0;
}

/* Priorité : code couleur vert / orange / rouge, fond plein + texte blanc
   pour que ce soit net d'un coup d'œil, dans le tableau comme dans la
   fiche détail. Même gabarit (hauteur, padding) que .attachment-link :
   c'est cette hauteur commune qui fixe la hauteur de toutes les lignes
   du tableau, quel que soit leur contenu (le statut, à côté, n'est plus
   qu'un texte coloré sans hauteur fixe, voir plus bas). */
.priority-badge {
	display: inline-flex;
	align-items: center;
	height: 22px;
	box-sizing: border-box;
	padding: 0 11px;
	border-radius: 11px;
	font-size: 0.82em;
	font-weight: 700;
	line-height: 1;
	white-space: nowrap;
	overflow: hidden;
	color: #fff;
}
.priority-badge.priority-low { background-color: #16a34a; }
.priority-badge.priority-normal { background-color: #ea580c; }
.priority-badge.priority-urgent { background-color: #dc2626; }
/* Statut : contrairement à priority-badge, pas de pastille pleine — juste du texte
   coloré en gras, pour que les deux colonnes se distinguent clairement d'un coup
   d'œil dans le tableau plutôt que d'aligner deux badges identiques. */
.status-badge {
	font-size: 0.82em;
	font-weight: 700;
	line-height: 1;
	white-space: nowrap;
	text-transform: uppercase;
	letter-spacing: 0.02em;
}
.status-badge.status-new { color: #0082c9; }
.status-badge.status-in_progress { color: #ea580c; }
.status-badge.status-resolved { color: #16a34a; }
.status-badge.status-closed { color: #767676; }
.priority-select {
	font-weight: 700;
	border-width: 2px;
}
.priority-select.priority-low { color: #16a34a; border-color: #16a34a; }
.priority-select.priority-normal { color: #ea580c; border-color: #ea580c; }
.priority-select.priority-urgent { color: #dc2626; border-color: #dc2626; }
.due-soon { color: #ea580c; font-weight: 600; }
.due-overdue { color: #dc2626; font-weight: 600; }
input[type="date"].due-soon, input[type="date"].due-overdue { font-weight: normal; }

/* Nom de la personne assignée, affiché en haut à droite de la modale de
   détail, juste à gauche du bouton de fermeture. */
.modal-assigned {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #767676);
	white-space: nowrap;
}

.ticket-detail-fields {
	display: flex;
	gap: 20px;
	flex-wrap: wrap;
	margin: 4px 0 20px;
	max-width: 320px;
}
.ticket-detail-description {
	margin: 0 0 20px;
	line-height: 1.5;
	white-space: pre-wrap;
}

.ticket-number { font-family: var(--font-face-mono, monospace); font-size: 0.9em; color: var(--color-text-maxcontrast, #767676); }
.unread-badge {
	display: inline-flex;
	align-items: center;
	height: 18px;
	box-sizing: border-box;
	padding: 0 8px;
	border-radius: 9px;
	background-color: #0082c9;
	color: #fff;
	font-family: var(--font-face, sans-serif);
	font-size: 0.72em;
	font-weight: 700;
	line-height: 1;
	text-transform: uppercase;
	letter-spacing: 0.02em;
	margin-right: 6px;
	vertical-align: middle;
	overflow: hidden;
}
tbody tr.ticket-unread { font-weight: 600; color: var(--color-main-text); }
tbody tr.ticket-unread td { color: var(--color-main-text); }
.ticket-list table { width: 100%; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; }
.pagination {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 16px;
}
.pagination button {
	padding: 6px 14px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}
.pagination button:disabled {
	opacity: 0.5;
	cursor: default;
}
.pagination-status {
	color: var(--color-text-maxcontrast, #767676);
	font-size: 0.9em;
}
th, td { text-align: left; padding: 4px 8px; border-bottom: 1px solid var(--color-border, #ddd); font-size: 0.92em; }
/* Hauteur de ligne fixe et identique pour toutes les lignes du tableau,
   quel que soit leur contenu (badge, bouton, tiret, texte simple...) :
   sans ça, les cellules aux contenus de gabarits différents (ex. bouton
   pièces jointes vs simple tiret) faisaient légèrement varier la hauteur
   d'une ligne à l'autre. */
.ticket-list tbody tr { height: 40px; }
.ticket-list td { vertical-align: middle; }
th { padding-top: 6px; padding-bottom: 6px; }
tbody tr { line-height: 1.3; }
th.sortable {
	cursor: pointer;
	user-select: none;
	white-space: nowrap;
}
th.sortable:hover { color: var(--color-primary-element, #0082c9); }
/* Flèche visible en permanence sur les colonnes triables (⇅, atténuée) pour
   signaler qu'elles sont cliquables, même hors survol ; devient une flèche
   simple et bien contrastée (▲/▼) une fois la colonne triée activement. */
.sort-arrow {
	display: inline-block;
	margin-left: 4px;
	font-size: 0.75em;
	opacity: 0.4;
}
th.sortable:hover .sort-arrow { opacity: 0.75; }
.sort-arrow-active {
	opacity: 1;
	color: var(--color-primary-element, #0082c9);
}
/* Compteurs de statut cliquables au-dessus du tableau : servent aussi de
   raccourci de filtre par statut (voir selectStatusFilter). */
.status-counts {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 10px 0 0;
}
.status-count {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: 16px;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 0.9em;
}
.status-count:hover { background-color: var(--color-background-hover, #f5f5f5); }
.status-count.active {
	border-color: var(--color-primary-element, #0082c9);
	background-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
}
.status-count-badge {
	display: inline-block;
	min-width: 1.4em;
	padding: 0 5px;
	border-radius: 8px;
	background-color: var(--color-background-hover, #f0f0f0);
	color: var(--color-main-text);
	font-weight: 700;
	font-size: 0.85em;
	text-align: center;
}
.status-count.active .status-count-badge {
	background-color: rgba(255, 255, 255, 0.25);
	color: var(--color-primary-element-text, #fff);
}
/* Icône de priorité : repère de forme en plus de la couleur, pour rester
   lisible sans dépendre uniquement du code couleur (accessibilité). */
.priority-icon {
	display: inline-block;
	margin-right: 2px;
}
.ticket-filters {
	display: flex;
	align-items: flex-end;
	flex-wrap: wrap;
	gap: 16px;
	margin: 10px 0 4px;
}
.filter-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}
.filter-field label {
	font-size: 0.8em;
	font-weight: 600;
	color: var(--color-text-maxcontrast, #767676);
}
.filter-field select,
.filter-field-search input {
	padding: 6px 8px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}
.filter-field-search {
	width: 600px;
}
.filter-field-search input {
	width: 600px;
}
.filter-reset {
	background: none;
	border: none;
	color: var(--color-primary-element, #0082c9);
	cursor: pointer;
	font-size: 0.9em;
	padding: 6px 0;
}
.filter-reset:hover { text-decoration: underline; }
tbody tr { cursor: pointer; }
tbody tr:hover { background: var(--color-background-hover, #f5f5f5); }
.ticket-detail-meta { color: var(--color-text-maxcontrast, #767676); font-size: 0.9em; margin: 0 0 4px; }
.comments { list-style: none; padding: 0; margin: 16px 0; }
.comments li {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 12px;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border, #eee);
}
.comment-message {
	flex: 1;
	min-width: 0;
}
.comment-message-text {
	white-space: pre-wrap;
	word-break: break-word;
}
.activity-entry {
	color: var(--color-text-maxcontrast, #767676);
	font-style: italic;
}
.activity-time {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast, #767676);
	font-size: 0.8em;
	white-space: nowrap;
}
.comment-form { display: flex; align-items: flex-end; gap: 8px; margin-top: 12px; }
.comment-form textarea {
	flex: 1;
	padding: 8px 10px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-family: inherit;
	font-size: inherit;
	resize: vertical;
}
.comment-form button.primary {
	padding: 8px 18px;
	border: none;
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	font-weight: 600;
	cursor: pointer;
}
.comment-form button.primary:hover { background-color: var(--color-primary-element-hover, #006aa3); }
.comments-locked {
	color: var(--color-text-maxcontrast, #767676);
	font-size: 0.9em;
	margin-top: 12px;
}
.attachments-section { margin: 0 0 16px; }
.attachments-section h3 { margin: 0 0 8px; font-size: 1em; }
.attachments-section-header {
	display: flex;
	align-items: center;
	gap: 6px;
}
.attachments-list { list-style: none; padding: 0; margin: 0 0 10px; }
.attachments-list li {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border, #eee);
}
.col-attachments { white-space: nowrap; text-align: center; }
.attachment-link {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	justify-content: center;
	height: 22px;
	min-height: 0;
	box-sizing: border-box;
	background: none;
	border: 1px solid var(--color-border, #ddd);
	border-radius: 11px;
	padding: 0 10px;
	line-height: 1;
	cursor: pointer;
	color: var(--color-main-text, #222);
	font-size: 0.82em;
	font-weight: 700;
	overflow: hidden;
}
/* Icône dossier agrandie par rapport au texte du compteur, tout en
   restant contenue dans la hauteur fixe du bouton (22px, cf. badges). */
.attachment-icon { font-size: 1.35em; line-height: 1; }
.attachment-link:hover { background: var(--color-background-hover, #f0f0f0); }
.attachment-none {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	height: 22px;
	box-sizing: border-box;
	line-height: 1;
	color: var(--color-text-maxcontrast, #767676);
}
.attachment-meta { color: var(--color-text-maxcontrast, #767676); font-size: 0.85em; }
.attachments-list .icon-button {
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-text-maxcontrast, #767676);
	padding: 2px 6px;
	text-decoration: none;
}
/* Seul le premier bouton du groupe (téléchargement) pousse l'ensemble à
   droite ; le suivant (suppression) s'aligne ensuite via le gap du <li>. */
.attachments-list .icon-button-group-start { margin-left: auto; }
.attachments-list a.icon-button:hover { color: var(--color-main-text, #222); }
.attachments-list button.icon-button:hover { color: var(--color-error, #c9302c); }
.field-hint {
	color: var(--color-text-maxcontrast, #767676);
	font-size: 0.85em;
	margin: 4px 0 0;
}

/* Bouton de suppression de ticket dans l'en-tête de la fiche détail (gestionnaire
   uniquement) : même famille visuelle que les icônes de pièce jointe, mais en rouge
   au survol pour signaler une action destructive. */
.modal-delete {
	background: none;
	border: none;
	cursor: pointer;
	font-size: 1.1em;
	padding: 2px 6px;
	color: var(--color-text-maxcontrast, #767676);
}
.modal-delete:hover { color: var(--color-error, #c9302c); }

/* Lien d'export Excel de la vue actuelle, au-dessus des compteurs de statut
   (gestionnaire uniquement). */
.ticket-list-actions {
	margin: 8px 0 0;
}
.export-link {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	color: var(--color-primary-element, #0082c9);
	text-decoration: none;
	font-size: 0.9em;
}
.export-link:hover { text-decoration: underline; }

/* Glisser-déposer de fichiers, en plus du <input type="file"> classique : la zone se
   met en évidence pendant le survol, comme un contour de dépôt standard. */
.dropzone {
	border: 2px dashed var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	padding: 10px;
	transition: border-color 0.15s, background-color 0.15s;
}
.dropzone.dragging {
	border-color: var(--color-primary-element, #0082c9);
	background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.08));
}
.dropzone-hint { font-style: italic; }

/* Vue mobile en cartes : sous 640px, le tableau (trop de colonnes pour un petit écran)
   se transforme en une pile de cartes, une par ticket. On garde la sémantique <table>
   (accessibilité, tri au clic) et on ne change que l'affichage : chaque <tr> devient un
   bloc, chaque <td> une ligne "étiquette : valeur" via data-label + content: attr(). */
@media (max-width: 640px) {
	.ticket-list table,
	.ticket-list thead,
	.ticket-list tbody,
	.ticket-list th,
	.ticket-list td,
	.ticket-list tr {
		display: block;
	}
	.ticket-list thead {
		position: absolute;
		width: 1px;
		height: 1px;
		overflow: hidden;
		clip: rect(0 0 0 0);
		white-space: nowrap;
	}
	.ticket-list tbody tr {
		height: auto;
		border: 1px solid var(--color-border, #ddd);
		border-radius: var(--border-radius, 4px);
		margin: 0 0 12px;
		padding: 8px 10px;
	}
	.ticket-list td {
		display: flex;
		justify-content: space-between;
		align-items: center;
		gap: 12px;
		text-align: right;
		border-bottom: 1px solid var(--color-border, #eee);
		padding: 6px 0;
	}
	.ticket-list td:last-child { border-bottom: none; }
	.ticket-list td::before {
		content: attr(data-label);
		font-weight: 600;
		color: var(--color-text-maxcontrast, #767676);
		text-align: left;
		margin-right: 12px;
	}
	.ticket-list td.ticket-number {
		justify-content: flex-start;
		flex-direction: row-reverse;
	}
	.ticket-list td.col-attachments { justify-content: space-between; }
}
</style>
