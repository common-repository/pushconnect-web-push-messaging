(() => {
  'use strict'

  const WebPush = {
    init () {
      self.addEventListener('install', self.skipWaiting);
      self.addEventListener('push', this.notificationPush.bind(this))
      self.addEventListener('notificationclick', this.notificationClick.bind(this))
      self.addEventListener('notificationclose', this.notificationClose.bind(this))
    },

    /**
     * Handle notification push event.
     *
     * https://developer.mozilla.org/en-US/docs/Web/Events/push
     *
     * @param  {NotificationEvent} event
     */
    notificationPush (event) {
      if (!(self.Notification && self.Notification.permission === 'granted')) {
        return
      }

      // Check if the notification has a payload.
      if (event.data) {
        event.waitUntil(
            this.sendNotification(event.data.json())
        )
      } else {
      // Otherwise just fetch the last notification from the server.
        event.waitUntil(
          self.registration.pushManager.getSubscription().then(subscription => {
            if (subscription) {
              return this.fetchNofication(subscription)
            }
          })
        )
      }
    },

    /**
     * Handle notification click event.
     *
     * https://developer.mozilla.org/en-US/docs/Web/Events/notificationclick
     *
     * @param  {NotificationEvent} event
     */
    notificationClick (event) {
      if (event.action === 'a') {  
        this.registerClick(event.notification.data.id, 'action_1', event.notification.data.action_links.a);
        this.openLink(event, event.notification.data.action_links.a);
      }  
      else if (event.action === 'b') {
        this.registerClick(event.notification.data.id, 'action_2', event.notification.data.action_links.b)
        this.openLink(event, event.notification.data.action_links.b);
      }  
      else {  
        this.registerClick(event.notification.data.id, 'url', event.notification.data.url)
        this.openLink(event, event.notification.data.url);
      }
    },
    
    openLink (event, url) {
        var vm = this;
        event.waitUntil(
            self.clients.matchAll({
              type: "window",
            }).then(function(clientList) {
                
                for (var i = 0; i < clientList.length; i++) {
                  if (clientList[i].url === url) {
                    return clientList[i].focus();
                  }
                }
                
                var newUrl = vm.appendTracking(url, event);                

                return self.clients.openWindow(newUrl);
            })
        );
    },
    
    updateQueryStringParameter(uri, key, value) {
        var re = new RegExp("([?&])" + key + "=.*?(&|$)", "i");
        var separator = uri.indexOf('?') !== -1 ? "&" : "?";
        if (uri.match(re)) {
          return uri; 
          return uri.replace(re, '$1' + key + "=" + value + '$2');
        }
        else {
          return uri + separator + key + "=" + value;
        }
    },
    
    appendTracking: function(url, event) {
        
        url = this.updateQueryStringParameter(url, 'utm_source', 'push_connect');
        
        if(event.notification.data.campaign) {
            url = this.updateQueryStringParameter(url, 'utm_campaign', event.notification.data.campaign.title);
        } else {
            url = this.updateQueryStringParameter(url, 'utm_campaign', 'generic');
        }
        
        url = this.updateQueryStringParameter(url, 'utm_medium', 'webpush');
       
        
        return url;
    },
    
    registerClick(notification_id, action, url) {
        return fetch('https://pushconnect.tech/tracker/' + notification_id + '/' + action + '/' + btoa(url)).then(response => {
            
        }).catch(function() {
            console.log("error caught but logged");
        });
    },

    /**
     * Handle notification close event (Chrome 50+).
     *
     * https://developers.google.com/web/updates/2016/03/notifications?hl=en
     *
     * @param  {NotificationEvent} event
     */
    notificationClose (event) {
      self.registration.pushManager.getSubscription().then(subscription => {
        if (subscription) {
          this.dismissNotification(event, subscription)
        }
      })
    },

    /**
     * Send notification to the user.
     *
     * @param  {PushMessageData|Object} data
     */
    sendNotification (data) {
      if(data.campaign && data.campaign.id) {
        var tag = data.campaign.id
      } else {
        var tag = data.tag;
      }
      return self.registration.showNotification(data.title, {
        body    : data.body,
        icon    : data.icon || '/notification-icon.png',
        badge   : data.badge || null,
        tag     : tag, //prevent the exact same campaign being displayed multiple times
        data    : data,
        image   : data.image,
        actions : data.actions || [],
        requireInteraction: true
      });
    },

    /**
     * Fetch the last notification from the server.
     *
     * @param    {String} subscription.endpoint
     * @return  {Response}
     */
    fetchNofication ({ endpoint }) {
      return fetch(`https://pushconnect.tech/push-notification/last?endpoint=${encodeURIComponent(endpoint)}`).then(response => {
        if (response.status !== 200) {
          throw new Error()
        }

        return response.json().then(data => {
          return this.sendNotification(data)
        })
      });
    },

    /**
     * Send request to server to dismiss a notification.
     *
     * @param    {NotificationEvent} event
     * @param    {String} subscription.endpoint
     * @return  {Response}
     */
    dismissNotification ({ notification }, { endpoint }) {
      if (!notification.data.id) {
        return
      }

      const data = new FormData()
      data.append('endpoint', endpoint)

      // Send a request to the server to mark the notification as read.
      fetch(`https://pushconnect.tech/push-notification/${notification.data.id}/dismiss`, {
        method: 'POST',
        body: data
      })
    }
  }

  WebPush.init()
})()