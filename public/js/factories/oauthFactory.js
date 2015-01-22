angular.module('eaApp').factory('oauthFactory', ['AuthenticationService', '$location', function(AuthenticationService, $location) {
    var oauthFactory = {};

    oauthFactory.onFacebookStatusChange = function(response) {
        switch(response.status) {
            case 'connected':
                AuthenticationService.facebookSignIn(response.authResponse).success(function(){
                    $location.path('/profile');
                });
                break;
            default:
                break;
        }
    };

    oauthFactory.onGoogleAuthResult = function(authResult) {
        if (authResult && authResult.access_token){
            AuthenticationService.googleSignIn(authResult).success(function(){
                $location.path('/profile');
            });
        } else {
            console.log('Not signed in to Google Plus');
        }
        
    };

    return oauthFactory;
}])
.directive('facebookLogin', ['$rootScope', function() {
    var directive = { restrict: 'E', replace: true, transclude: true };
    directive.template =
        '<fb:login-button scope="email" data-size="large" data-show-faces="false">' +
        '</fb:login-button>';

    directive.link = function (scope, iElement, iAttrs) {
        if (FB) {
            // tell facebook to render the login button
            FB.XFBML.parse(iElement[0].parent);
        }
    };
        
    return directive;
}])
.directive('googleLogin', ['$window', function ($window) {
    var ending = /\.apps\.googleusercontent\.com$/;

    var directive = {
      restrict: 'E',
      transclude: true,
      replace: true };

    directive.template = 
        '<span></span>';

    directive.link = function (scope, iElement, attrs) {
        attrs.$set('clientid', $window.clientId);
        attrs.clientid += (ending.test(attrs.clientid) ? '' : '.apps.googleusercontent.com');

        attrs.$set('data-clientid', attrs.clientid);

        // Some default values, based on prior versions of this directive
        var defaults = {
          callback: 'signInCallback',
          cookiepolicy: 'single_host_origin',
          //requestvisibleactions: 'http://schemas.google.com/AddActivity',
          scope: 'https://www.googleapis.com/auth/userinfo.email',
          //width: 'wide',
          redirecturi: 'postmessage',
          accesstype: 'offline',
        };

        defaults.clientid = attrs.clientid;

        // Provide default values if not explicitly set
        angular.forEach(Object.getOwnPropertyNames(defaults), function(propName) {
          if (!attrs.hasOwnProperty(propName)) {
            attrs.$set('data-' + propName, defaults[propName]);
          }
        });

        gapi.signin.render(iElement[0], defaults
        );
        //gapi.signin.render(iElement[0]);
    };

    return directive;
}])
.run(['$window', 'oauthFactory', function ($window, oauthFactory) {
    window.fbAsyncInit = function () {
        FB.init({
            appId: window.fbAppId,
            status:true,
            cookie:true,
            xfbml:true,
            version: 'v2.2'
        });
        
        FB.Event.subscribe('auth.statusChange', function(response) {
            oauthFactory.onFacebookStatusChange(response);
        });
    };

    (function (d, s, id){
       var js, fjs = d.getElementsByTagName(s)[0];
       if (d.getElementById(id)) {return;}
       js = d.createElement(s); js.id = id;
       js.src = '//connect.facebook.net/en_US/sdk.js';
       fjs.parentNode.insertBefore(js, fjs);
    }(document, 'script', 'facebook-jssdk'));

    (function (d, s, id, gid){
       var js, fjs = d.getElementsByTagName(s)[0];
       if (d.getElementById(id)) {return;}
       js = d.createElement(s); js.id = id;
       js.type = 'text/javascript';
       js.async = true;
       js.src = 'https://apis.google.com/js/client:plusone.js';
       fjs.parentNode.insertBefore(js, fjs);
    }(document, 'script', 'google-jssdk'));

    $window.signInCallback = function (authResult) {
        oauthFactory.onGoogleAuthResult(authResult);
    }
}]);
        