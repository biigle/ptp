(()=>{"use strict";const t=Vue.resource("api/v1/send-ptp-job{/id}");var e=biigle.$require("messages");var n=function(t,e,n,s,o,i,r,a){var l,c="function"==typeof t?t.options:t;if(e&&(c.render=e,c.staticRenderFns=n,c._compiled=!0),s&&(c.functional=!0),i&&(c._scopeId="data-v-"+i),r?(l=function(t){(t=t||this.$vnode&&this.$vnode.ssrContext||this.parent&&this.parent.$vnode&&this.parent.$vnode.ssrContext)||"undefined"==typeof __VUE_SSR_CONTEXT__||(t=__VUE_SSR_CONTEXT__),o&&o.call(this,t),t&&t._registeredComponents&&t._registeredComponents.add(r)},c._ssrRegister=l):o&&(l=a?function(){o.call(this,(c.functional?this.parent:this).$root.$options.shadowRoot)}:o),l)if(c.functional){c._injectStyles=l;var u=c.render;c.render=function(t,e){return l.call(e),u(t,e)}}else{var d=c.beforeCreate;c.beforeCreate=d?[].concat(d,l):[l]}return{exports:t,options:c}}({data:function(){return{volumeId:biigle.$require("volumes.volumeId"),selectedLabel:null}},methods:{sendPtpRequest:function(){t.save({id:this.volumeId},{}).catch((function(t){400==t.status&&e.danger(t.body.message)}))}}},(function(){var t=this,e=t.$createElement,n=t._self._c||e;return n("div",{staticClass:"ptp-container"},[n("form",{staticClass:"form-stacked"},[t._m(0),t._v(" "),n("div",{staticClass:"form-group"},[n("button",{staticClass:"btn btn-success btn-block",attrs:{type:"button",title:"Run Point to Polygon conversion on this volume"},on:{click:t.sendPtpRequest}},[t._v("Submit")])])])])}),[function(){var t=this,e=t.$createElement,n=t._self._c||e;return n("div",{staticClass:"form-group"},[n("h4",[t._v("Create a new Point to Polygon job  "),n("span",{staticClass:"label label-warning"},[t._v("experimental")])]),t._v(" "),n("span",[t._v("Run the point to polygon transformation using Magic SAM")]),n("br")])}],!1,null,null,null);const s=n.exports;biigle.$mount("ptp-container",s)})();