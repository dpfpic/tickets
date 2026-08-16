import Vue from 'vue'
import Admin from './Admin.vue'

// t() est fourni globalement par le noyau Nextcloud (script core/l10n),
// déjà chargé sur toute page d'admin authentifiée.
Vue.mixin({
	methods: {
		t: (app, text, vars, count) => window.t(app, text, vars, count),
	},
})

new Vue({
	el: '#tickets-admin-settings',
	render: h => h(Admin),
})
