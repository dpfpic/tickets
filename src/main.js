import Vue from 'vue'
import App from './App.vue'

// t() et n() sont fournis globalement par le noyau Nextcloud (script core/l10n),
// déjà chargé sur toute page authentifiée. On les expose comme méthodes Vue
// pour pouvoir écrire this.t('tickets', '...') dans les composants.
Vue.mixin({
	methods: {
		t: (app, text, vars, count) => window.t(app, text, vars, count),
		n: (app, textSingular, textPlural, count, vars) => window.n(app, textSingular, textPlural, count, vars),
	},
})

new Vue({
	el: '#tickets-app',
	render: h => h(App),
})
