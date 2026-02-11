<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta content="IE=edge,chrome=1" http-equiv="X-UA-Compatible">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Laravel API Documentation</title>

    <link href="https://fonts.googleapis.com/css?family=Open+Sans&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{ asset("/vendor/scribe/css/theme-default.style.css") }}" media="screen">
    <link rel="stylesheet" href="{{ asset("/vendor/scribe/css/theme-default.print.css") }}" media="print">

    <script src="https://cdn.jsdelivr.net/npm/lodash@4.17.10/lodash.min.js"></script>

    <link rel="stylesheet"
          href="https://unpkg.com/@highlightjs/cdn-assets@11.6.0/styles/obsidian.min.css">
    <script src="https://unpkg.com/@highlightjs/cdn-assets@11.6.0/highlight.min.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jets/0.14.1/jets.min.js"></script>

    <style id="language-style">
        /* starts out as display none and is replaced with js later  */
                    body .content .bash-example code { display: none; }
                    body .content .javascript-example code { display: none; }
            </style>

    <script>
        var tryItOutBaseUrl = "http://localhost:8000";
        var useCsrf = Boolean();
        var csrfUrl = "/sanctum/csrf-cookie";
    </script>
    <script src="{{ asset("/vendor/scribe/js/tryitout-5.6.0.js") }}"></script>

    <script src="{{ asset("/vendor/scribe/js/theme-default-5.6.0.js") }}"></script>

</head>

<body data-languages="[&quot;bash&quot;,&quot;javascript&quot;]">

<a href="#" id="nav-button">
    <span>
        MENU
        <img src="{{ asset("/vendor/scribe/images/navbar.png") }}" alt="navbar-image"/>
    </span>
</a>
<div class="tocify-wrapper">
    
            <div class="lang-selector">
                                            <button type="button" class="lang-button" data-language-name="bash">bash</button>
                                            <button type="button" class="lang-button" data-language-name="javascript">javascript</button>
                    </div>
    
    <div class="search">
        <input type="text" class="search" id="input-search" placeholder="Search">
    </div>

    <div id="toc">
                    <ul id="tocify-header-introduction" class="tocify-header">
                <li class="tocify-item level-1" data-unique="introduction">
                    <a href="#introduction">Introduction</a>
                </li>
                            </ul>
                    <ul id="tocify-header-authenticating-requests" class="tocify-header">
                <li class="tocify-item level-1" data-unique="authenticating-requests">
                    <a href="#authenticating-requests">Authenticating requests</a>
                </li>
                            </ul>
                    <ul id="tocify-header-action-improvements" class="tocify-header">
                <li class="tocify-item level-1" data-unique="action-improvements">
                    <a href="#action-improvements">Action Improvements</a>
                </li>
                                    <ul id="tocify-subheader-action-improvements" class="tocify-subheader">
                                                    <li class="tocify-item level-2" data-unique="action-improvements-GETapi-incidents--incident_id--action-improvements">
                                <a href="#action-improvements-GETapi-incidents--incident_id--action-improvements">List action improvements for an incident</a>
                            </li>
                                                                                <li class="tocify-item level-2" data-unique="action-improvements-GETapi-action-improvements--action_improvement_id-">
                                <a href="#action-improvements-GETapi-action-improvements--action_improvement_id-">Get action improvement by ID</a>
                            </li>
                                                                        </ul>
                            </ul>
                    <ul id="tocify-header-authentication" class="tocify-header">
                <li class="tocify-item level-1" data-unique="authentication">
                    <a href="#authentication">Authentication</a>
                </li>
                                    <ul id="tocify-subheader-authentication" class="tocify-subheader">
                                                    <li class="tocify-item level-2" data-unique="authentication-POSTapi-login">
                                <a href="#authentication-POSTapi-login">Login</a>
                            </li>
                                                                        </ul>
                            </ul>
                    <ul id="tocify-header-incidents" class="tocify-header">
                <li class="tocify-item level-1" data-unique="incidents">
                    <a href="#incidents">Incidents</a>
                </li>
                                    <ul id="tocify-subheader-incidents" class="tocify-subheader">
                                                    <li class="tocify-item level-2" data-unique="incidents-GETapi-v1-incidents">
                                <a href="#incidents-GETapi-v1-incidents">List incidents</a>
                            </li>
                                                                                <li class="tocify-item level-2" data-unique="incidents-GETapi-v1-incidents--id-">
                                <a href="#incidents-GETapi-v1-incidents--id-">Get incident by ID</a>
                            </li>
                                                                                <li class="tocify-item level-2" data-unique="incidents-GETapi-v1-incidents-by-no--no-">
                                <a href="#incidents-GETapi-v1-incidents-by-no--no-">Get incident by incident number</a>
                            </li>
                                                                                <li class="tocify-item level-2" data-unique="incidents-GETapi-v1-incidents-by-no--no--markdown">
                                <a href="#incidents-GETapi-v1-incidents-by-no--no--markdown">Export incident as Markdown</a>
                            </li>
                                                                                <li class="tocify-item level-2" data-unique="incidents-GETapi-v1-labels">
                                <a href="#incidents-GETapi-v1-labels">Get all labels</a>
                            </li>
                                                                                <li class="tocify-item level-2" data-unique="incidents-GETapi-v1-incident-types">
                                <a href="#incidents-GETapi-v1-incident-types">Get all incident types</a>
                            </li>
                                                                        </ul>
                            </ul>
            </div>

    <ul class="toc-footer" id="toc-footer">
                    <li style="padding-bottom: 5px;"><a href="{{ route("scribe.postman") }}">View Postman collection</a></li>
                            <li style="padding-bottom: 5px;"><a href="{{ route("scribe.openapi") }}">View OpenAPI spec</a></li>
                <li><a href="http://github.com/knuckleswtf/scribe">Documentation powered by Scribe ✍</a></li>
    </ul>

    <ul class="toc-footer" id="last-updated">
        <li>Last updated: February 11, 2026</li>
    </ul>
</div>

<div class="page-wrapper">
    <div class="dark-box"></div>
    <div class="content">
        <h1 id="introduction">Introduction</h1>
<aside>
    <strong>Base URL</strong>: <code>http://localhost:8000</code>
</aside>
<pre><code>This documentation aims to provide all the information you need to work with our API.

&lt;aside&gt;As you scroll, you'll see code examples for working with the API in different programming languages in the dark area to the right (or as part of the content on mobile).
You can switch the language used with the tabs at the top right (or from the nav menu at the top left on mobile).&lt;/aside&gt;</code></pre>

        <h1 id="authenticating-requests">Authenticating requests</h1>
<p>To authenticate requests, include a <strong><code>Authorization</code></strong> header with the value <strong><code>"Bearer {YOUR_AUTH_KEY}"</code></strong>.</p>
<p>All authenticated endpoints are marked with a <code>requires authentication</code> badge in the documentation below.</p>
<p>You can retrieve your token by using the /login endpoint.</p>

        <h1 id="action-improvements">Action Improvements</h1>

    <p>APIs for managing action improvements associated with incidents.
Action improvements are corrective or preventive actions taken in response to incidents.
All endpoints require authentication via Bearer token.</p>

                                <h2 id="action-improvements-GETapi-incidents--incident_id--action-improvements">List action improvements for an incident</h2>

<p>
<small class="badge badge-darkred">requires authentication</small>
</p>

<p>Retrieve all action improvements associated with a specific incident.</p>

<span id="example-requests-GETapi-incidents--incident_id--action-improvements">
<blockquote>Example request:</blockquote>


<div class="bash-example">
    <pre><code class="language-bash">curl --request GET \
    --get "http://localhost:8000/api/incidents/1/action-improvements" \
    --header "Authorization: Bearer {YOUR_AUTH_KEY}" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json"</code></pre></div>


<div class="javascript-example">
    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/incidents/1/action-improvements"
);

const headers = {
    "Authorization": "Bearer {YOUR_AUTH_KEY}",
    "Content-Type": "application/json",
    "Accept": "application/json",
};

fetch(url, {
    method: "GET",
    headers,
}).then(response =&gt; response.json());</code></pre></div>

</span>

<span id="example-responses-GETapi-incidents--incident_id--action-improvements">
            <blockquote>
            <p>Example response (200):</p>
        </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;code&quot;: 200,
    &quot;status&quot;: &quot;Success&quot;,
    &quot;message&quot;: &quot;Action improvements retrieved successfully.&quot;,
    &quot;data&quot;: [
        {
            &quot;id&quot;: 1,
            &quot;title&quot;: &quot;Increase connection pool size&quot;,
            &quot;detail&quot;: &quot;Configure pool to handle 2x peak traffic&quot;,
            &quot;status&quot;: &quot;pending&quot;,
            &quot;due_date&quot;: &quot;2025-01-20&quot;,
            &quot;pic_email&quot;: [
                &quot;john.doe@company.com&quot;,
                &quot;jane.smith@company.com&quot;
            ]
        }
    ]
}</code>
 </pre>
    </span>
<span id="execution-results-GETapi-incidents--incident_id--action-improvements" hidden>
    <blockquote>Received response<span
                id="execution-response-status-GETapi-incidents--incident_id--action-improvements"></span>:
    </blockquote>
    <pre class="json"><code id="execution-response-content-GETapi-incidents--incident_id--action-improvements"
      data-empty-response-text="<Empty response>" style="max-height: 400px;"></code></pre>
</span>
<span id="execution-error-GETapi-incidents--incident_id--action-improvements" hidden>
    <blockquote>Request failed with error:</blockquote>
    <pre><code id="execution-error-message-GETapi-incidents--incident_id--action-improvements">

Tip: Check that you&#039;re properly connected to the network.
If you&#039;re a maintainer of ths API, verify that your API is running and you&#039;ve enabled CORS.
You can check the Dev Tools console for debugging information.</code></pre>
</span>
<form id="form-GETapi-incidents--incident_id--action-improvements" data-method="GET"
      data-path="api/incidents/{incident_id}/action-improvements"
      data-authed="1"
      data-hasfiles="0"
      data-isarraybody="0"
      autocomplete="off"
      onsubmit="event.preventDefault(); executeTryOut('GETapi-incidents--incident_id--action-improvements', this);">
    <h3>
        Request&nbsp;&nbsp;&nbsp;
                    <button type="button"
                    style="background-color: #8fbcd4; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-tryout-GETapi-incidents--incident_id--action-improvements"
                    onclick="tryItOut('GETapi-incidents--incident_id--action-improvements');">Try it out ⚡
            </button>
            <button type="button"
                    style="background-color: #c97a7e; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-canceltryout-GETapi-incidents--incident_id--action-improvements"
                    onclick="cancelTryOut('GETapi-incidents--incident_id--action-improvements');" hidden>Cancel 🛑
            </button>&nbsp;&nbsp;
            <button type="submit"
                    style="background-color: #6ac174; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-executetryout-GETapi-incidents--incident_id--action-improvements"
                    data-initial-text="Send Request 💥"
                    data-loading-text="⏱ Sending..."
                    hidden>Send Request 💥
            </button>
            </h3>
            <p>
            <small class="badge badge-green">GET</small>
            <b><code>api/incidents/{incident_id}/action-improvements</code></b>
        </p>
                <h4 class="fancy-heading-panel"><b>Headers</b></h4>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Authorization</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Authorization" class="auth-value"               data-endpoint="GETapi-incidents--incident_id--action-improvements"
               value="Bearer {YOUR_AUTH_KEY}"
               data-component="header">
    <br>
<p>Example: <code>Bearer {YOUR_AUTH_KEY}</code></p>
            </div>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Content-Type</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Content-Type"                data-endpoint="GETapi-incidents--incident_id--action-improvements"
               value="application/json"
               data-component="header">
    <br>
<p>Example: <code>application/json</code></p>
            </div>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Accept</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Accept"                data-endpoint="GETapi-incidents--incident_id--action-improvements"
               value="application/json"
               data-component="header">
    <br>
<p>Example: <code>application/json</code></p>
            </div>
                        <h4 class="fancy-heading-panel"><b>URL Parameters</b></h4>
                    <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>incident_id</code></b>&nbsp;&nbsp;
<small>integer</small>&nbsp;
 &nbsp;
 &nbsp;
                <input type="number" style="display: none"
               step="any"               name="incident_id"                data-endpoint="GETapi-incidents--incident_id--action-improvements"
               value="1"
               data-component="url">
    <br>
<p>The ID of the incident. Example: <code>1</code></p>
            </div>
                    <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>incident</code></b>&nbsp;&nbsp;
<small>integer</small>&nbsp;
 &nbsp;
 &nbsp;
                <input type="number" style="display: none"
               step="any"               name="incident"                data-endpoint="GETapi-incidents--incident_id--action-improvements"
               value="1"
               data-component="url">
    <br>
<p>The ID of the incident. Example: <code>1</code></p>
            </div>
                    </form>

                    <h2 id="action-improvements-GETapi-action-improvements--action_improvement_id-">Get action improvement by ID</h2>

<p>
<small class="badge badge-darkred">requires authentication</small>
</p>

<p>Retrieve detailed information about a specific action improvement.</p>

<span id="example-requests-GETapi-action-improvements--action_improvement_id-">
<blockquote>Example request:</blockquote>


<div class="bash-example">
    <pre><code class="language-bash">curl --request GET \
    --get "http://localhost:8000/api/action-improvements/5" \
    --header "Authorization: Bearer {YOUR_AUTH_KEY}" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json"</code></pre></div>


<div class="javascript-example">
    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/action-improvements/5"
);

const headers = {
    "Authorization": "Bearer {YOUR_AUTH_KEY}",
    "Content-Type": "application/json",
    "Accept": "application/json",
};

fetch(url, {
    method: "GET",
    headers,
}).then(response =&gt; response.json());</code></pre></div>

</span>

<span id="example-responses-GETapi-action-improvements--action_improvement_id-">
            <blockquote>
            <p>Example response (200):</p>
        </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;code&quot;: 200,
    &quot;status&quot;: &quot;Success&quot;,
    &quot;message&quot;: &quot;Action improvement retrieved successfully.&quot;,
    &quot;data&quot;: {
        &quot;id&quot;: 1,
        &quot;title&quot;: &quot;Increase connection pool size&quot;,
        &quot;detail&quot;: &quot;Configure pool to handle 2x peak traffic&quot;,
        &quot;status&quot;: &quot;pending&quot;,
        &quot;due_date&quot;: &quot;2025-01-20&quot;,
        &quot;pic_email&quot;: [
            &quot;john.doe@company.com&quot;
        ]
    }
}</code>
 </pre>
            <blockquote>
            <p>Example response (404):</p>
        </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;code&quot;: 404,
    &quot;status&quot;: &quot;Error&quot;,
    &quot;message&quot;: &quot;Action improvement not found.&quot;,
    &quot;data&quot;: null
}</code>
 </pre>
    </span>
<span id="execution-results-GETapi-action-improvements--action_improvement_id-" hidden>
    <blockquote>Received response<span
                id="execution-response-status-GETapi-action-improvements--action_improvement_id-"></span>:
    </blockquote>
    <pre class="json"><code id="execution-response-content-GETapi-action-improvements--action_improvement_id-"
      data-empty-response-text="<Empty response>" style="max-height: 400px;"></code></pre>
</span>
<span id="execution-error-GETapi-action-improvements--action_improvement_id-" hidden>
    <blockquote>Request failed with error:</blockquote>
    <pre><code id="execution-error-message-GETapi-action-improvements--action_improvement_id-">

Tip: Check that you&#039;re properly connected to the network.
If you&#039;re a maintainer of ths API, verify that your API is running and you&#039;ve enabled CORS.
You can check the Dev Tools console for debugging information.</code></pre>
</span>
<form id="form-GETapi-action-improvements--action_improvement_id-" data-method="GET"
      data-path="api/action-improvements/{action_improvement_id}"
      data-authed="1"
      data-hasfiles="0"
      data-isarraybody="0"
      autocomplete="off"
      onsubmit="event.preventDefault(); executeTryOut('GETapi-action-improvements--action_improvement_id-', this);">
    <h3>
        Request&nbsp;&nbsp;&nbsp;
                    <button type="button"
                    style="background-color: #8fbcd4; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-tryout-GETapi-action-improvements--action_improvement_id-"
                    onclick="tryItOut('GETapi-action-improvements--action_improvement_id-');">Try it out ⚡
            </button>
            <button type="button"
                    style="background-color: #c97a7e; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-canceltryout-GETapi-action-improvements--action_improvement_id-"
                    onclick="cancelTryOut('GETapi-action-improvements--action_improvement_id-');" hidden>Cancel 🛑
            </button>&nbsp;&nbsp;
            <button type="submit"
                    style="background-color: #6ac174; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-executetryout-GETapi-action-improvements--action_improvement_id-"
                    data-initial-text="Send Request 💥"
                    data-loading-text="⏱ Sending..."
                    hidden>Send Request 💥
            </button>
            </h3>
            <p>
            <small class="badge badge-green">GET</small>
            <b><code>api/action-improvements/{action_improvement_id}</code></b>
        </p>
                <h4 class="fancy-heading-panel"><b>Headers</b></h4>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Authorization</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Authorization" class="auth-value"               data-endpoint="GETapi-action-improvements--action_improvement_id-"
               value="Bearer {YOUR_AUTH_KEY}"
               data-component="header">
    <br>
<p>Example: <code>Bearer {YOUR_AUTH_KEY}</code></p>
            </div>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Content-Type</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Content-Type"                data-endpoint="GETapi-action-improvements--action_improvement_id-"
               value="application/json"
               data-component="header">
    <br>
<p>Example: <code>application/json</code></p>
            </div>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Accept</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Accept"                data-endpoint="GETapi-action-improvements--action_improvement_id-"
               value="application/json"
               data-component="header">
    <br>
<p>Example: <code>application/json</code></p>
            </div>
                        <h4 class="fancy-heading-panel"><b>URL Parameters</b></h4>
                    <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>action_improvement_id</code></b>&nbsp;&nbsp;
<small>integer</small>&nbsp;
 &nbsp;
 &nbsp;
                <input type="number" style="display: none"
               step="any"               name="action_improvement_id"                data-endpoint="GETapi-action-improvements--action_improvement_id-"
               value="5"
               data-component="url">
    <br>
<p>The ID of the action improvement. Example: <code>5</code></p>
            </div>
                    <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>action_improvement</code></b>&nbsp;&nbsp;
<small>integer</small>&nbsp;
 &nbsp;
 &nbsp;
                <input type="number" style="display: none"
               step="any"               name="action_improvement"                data-endpoint="GETapi-action-improvements--action_improvement_id-"
               value="1"
               data-component="url">
    <br>
<p>The ID of the action improvement. Example: <code>1</code></p>
            </div>
                    </form>

                <h1 id="authentication">Authentication</h1>

    <p>APIs for user authentication and token management.</p>

                                <h2 id="authentication-POSTapi-login">Login</h2>

<p>
<small class="badge badge-darkred">requires authentication</small>
</p>

<p>Authenticate a user and return an API bearer token.
The token must be included in the Authorization header for subsequent API requests.</p>

<span id="example-requests-POSTapi-login">
<blockquote>Example request:</blockquote>


<div class="bash-example">
    <pre><code class="language-bash">curl --request POST \
    "http://localhost:8000/api/login" \
    --header "Authorization: Bearer {YOUR_AUTH_KEY}" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json" \
    --data "{
    \"email\": \"admin@example.com\",
    \"password\": \"password123\"
}"
</code></pre></div>


<div class="javascript-example">
    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/login"
);

const headers = {
    "Authorization": "Bearer {YOUR_AUTH_KEY}",
    "Content-Type": "application/json",
    "Accept": "application/json",
};

let body = {
    "email": "admin@example.com",
    "password": "password123"
};

fetch(url, {
    method: "POST",
    headers,
    body: JSON.stringify(body),
}).then(response =&gt; response.json());</code></pre></div>

</span>

<span id="example-responses-POSTapi-login">
            <blockquote>
            <p>Example response (200):</p>
        </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;code&quot;: 200,
    &quot;status&quot;: &quot;Success&quot;,
    &quot;message&quot;: &quot;Login successful.&quot;,
    &quot;data&quot;: {
        &quot;token&quot;: &quot;1|aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890&quot;
    }
}</code>
 </pre>
            <blockquote>
            <p>Example response (401):</p>
        </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;code&quot;: 401,
    &quot;status&quot;: &quot;Error&quot;,
    &quot;message&quot;: &quot;Invalid credentials.&quot;,
    &quot;data&quot;: null
}</code>
 </pre>
            <blockquote>
            <p>Example response (422):</p>
        </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;code&quot;: 422,
    &quot;status&quot;: &quot;Error&quot;,
    &quot;message&quot;: &quot;The email field is required. (and 1 more error)&quot;,
    &quot;data&quot;: {
        &quot;email&quot;: [
            &quot;The email field is required.&quot;
        ],
        &quot;password&quot;: [
            &quot;The password field is required.&quot;
        ]
    }
}</code>
 </pre>
    </span>
<span id="execution-results-POSTapi-login" hidden>
    <blockquote>Received response<span
                id="execution-response-status-POSTapi-login"></span>:
    </blockquote>
    <pre class="json"><code id="execution-response-content-POSTapi-login"
      data-empty-response-text="<Empty response>" style="max-height: 400px;"></code></pre>
</span>
<span id="execution-error-POSTapi-login" hidden>
    <blockquote>Request failed with error:</blockquote>
    <pre><code id="execution-error-message-POSTapi-login">

Tip: Check that you&#039;re properly connected to the network.
If you&#039;re a maintainer of ths API, verify that your API is running and you&#039;ve enabled CORS.
You can check the Dev Tools console for debugging information.</code></pre>
</span>
<form id="form-POSTapi-login" data-method="POST"
      data-path="api/login"
      data-authed="1"
      data-hasfiles="0"
      data-isarraybody="0"
      autocomplete="off"
      onsubmit="event.preventDefault(); executeTryOut('POSTapi-login', this);">
    <h3>
        Request&nbsp;&nbsp;&nbsp;
                    <button type="button"
                    style="background-color: #8fbcd4; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-tryout-POSTapi-login"
                    onclick="tryItOut('POSTapi-login');">Try it out ⚡
            </button>
            <button type="button"
                    style="background-color: #c97a7e; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-canceltryout-POSTapi-login"
                    onclick="cancelTryOut('POSTapi-login');" hidden>Cancel 🛑
            </button>&nbsp;&nbsp;
            <button type="submit"
                    style="background-color: #6ac174; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-executetryout-POSTapi-login"
                    data-initial-text="Send Request 💥"
                    data-loading-text="⏱ Sending..."
                    hidden>Send Request 💥
            </button>
            </h3>
            <p>
            <small class="badge badge-black">POST</small>
            <b><code>api/login</code></b>
        </p>
                <h4 class="fancy-heading-panel"><b>Headers</b></h4>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Authorization</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Authorization" class="auth-value"               data-endpoint="POSTapi-login"
               value="Bearer {YOUR_AUTH_KEY}"
               data-component="header">
    <br>
<p>Example: <code>Bearer {YOUR_AUTH_KEY}</code></p>
            </div>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Content-Type</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Content-Type"                data-endpoint="POSTapi-login"
               value="application/json"
               data-component="header">
    <br>
<p>Example: <code>application/json</code></p>
            </div>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Accept</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Accept"                data-endpoint="POSTapi-login"
               value="application/json"
               data-component="header">
    <br>
<p>Example: <code>application/json</code></p>
            </div>
                                <h4 class="fancy-heading-panel"><b>Body Parameters</b></h4>
        <div style=" padding-left: 28px;  clear: unset;">
            <b style="line-height: 2;"><code>email</code></b>&nbsp;&nbsp;
<small>string</small>&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="email"                data-endpoint="POSTapi-login"
               value="admin@example.com"
               data-component="body">
    <br>
<p>The user's email address. Example: <code>admin@example.com</code></p>
        </div>
                <div style=" padding-left: 28px;  clear: unset;">
            <b style="line-height: 2;"><code>password</code></b>&nbsp;&nbsp;
<small>string</small>&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="password"                data-endpoint="POSTapi-login"
               value="password123"
               data-component="body">
    <br>
<p>The user's password. Example: <code>password123</code></p>
        </div>
        </form>

                <h1 id="incidents">Incidents</h1>

    <p>APIs for managing and retrieving technical incidents and issues.
All endpoints require authentication via Bearer token.</p>

                                <h2 id="incidents-GETapi-v1-incidents">List incidents</h2>

<p>
<small class="badge badge-darkred">requires authentication</small>
</p>

<p>Retrieve a paginated list of incidents with optional filtering.
Results are ordered by incident date (newest first) and include associated labels.</p>

<span id="example-requests-GETapi-v1-incidents">
<blockquote>Example request:</blockquote>


<div class="bash-example">
    <pre><code class="language-bash">curl --request GET \
    --get "http://localhost:8000/api/v1/incidents?start_date=2024-01-01&amp;end_date=2024-12-31&amp;min_fund_loss=1000000&amp;max_fund_loss=50000000&amp;min_potential_fund_loss=1000000&amp;max_potential_fund_loss=100000000&amp;tags=payment%2Cdatabase%2Ctimeout&amp;type=Tech&amp;page=1" \
    --header "Authorization: Bearer {YOUR_AUTH_KEY}" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json"</code></pre></div>


<div class="javascript-example">
    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/v1/incidents"
);

const params = {
    "start_date": "2024-01-01",
    "end_date": "2024-12-31",
    "min_fund_loss": "1000000",
    "max_fund_loss": "50000000",
    "min_potential_fund_loss": "1000000",
    "max_potential_fund_loss": "100000000",
    "tags": "payment,database,timeout",
    "type": "Tech",
    "page": "1",
};
Object.keys(params)
    .forEach(key =&gt; url.searchParams.append(key, params[key]));

const headers = {
    "Authorization": "Bearer {YOUR_AUTH_KEY}",
    "Content-Type": "application/json",
    "Accept": "application/json",
};

fetch(url, {
    method: "GET",
    headers,
}).then(response =&gt; response.json());</code></pre></div>

</span>

<span id="example-responses-GETapi-v1-incidents">
            <blockquote>
            <p>Example response (200):</p>
        </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;code&quot;: 200,
    &quot;status&quot;: &quot;Success&quot;,
    &quot;message&quot;: &quot;Incidents retrieved successfully.&quot;,
    &quot;data&quot;: {
        &quot;data&quot;: [
            {
                &quot;id&quot;: 1,
                &quot;no&quot;: &quot;20250115_IN_1234&quot;,
                &quot;title&quot;: &quot;Payment Gateway Timeout&quot;,
                &quot;summary&quot;: &quot;5-minute outage during peak hours...&quot;,
                &quot;severity&quot;: &quot;P1&quot;,
                &quot;incident_type&quot;: &quot;Tech&quot;,
                &quot;incident_date&quot;: &quot;2025-01-15T10:30:00.000000Z&quot;,
                &quot;fund_loss&quot;: 5000000,
                &quot;labels&quot;: [
                    &quot;payment&quot;,
                    &quot;database&quot;
                ]
            }
        ],
        &quot;current_page&quot;: 1,
        &quot;per_page&quot;: 15,
        &quot;total&quot;: 42
    }
}</code>
 </pre>
            <blockquote>
            <p>Example response (500):</p>
        </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;code&quot;: 500,
    &quot;status&quot;: &quot;Error&quot;,
    &quot;message&quot;: &quot;Failed to retrieve incidents.&quot;,
    &quot;data&quot;: null
}</code>
 </pre>
    </span>
<span id="execution-results-GETapi-v1-incidents" hidden>
    <blockquote>Received response<span
                id="execution-response-status-GETapi-v1-incidents"></span>:
    </blockquote>
    <pre class="json"><code id="execution-response-content-GETapi-v1-incidents"
      data-empty-response-text="<Empty response>" style="max-height: 400px;"></code></pre>
</span>
<span id="execution-error-GETapi-v1-incidents" hidden>
    <blockquote>Request failed with error:</blockquote>
    <pre><code id="execution-error-message-GETapi-v1-incidents">

Tip: Check that you&#039;re properly connected to the network.
If you&#039;re a maintainer of ths API, verify that your API is running and you&#039;ve enabled CORS.
You can check the Dev Tools console for debugging information.</code></pre>
</span>
<form id="form-GETapi-v1-incidents" data-method="GET"
      data-path="api/v1/incidents"
      data-authed="1"
      data-hasfiles="0"
      data-isarraybody="0"
      autocomplete="off"
      onsubmit="event.preventDefault(); executeTryOut('GETapi-v1-incidents', this);">
    <h3>
        Request&nbsp;&nbsp;&nbsp;
                    <button type="button"
                    style="background-color: #8fbcd4; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-tryout-GETapi-v1-incidents"
                    onclick="tryItOut('GETapi-v1-incidents');">Try it out ⚡
            </button>
            <button type="button"
                    style="background-color: #c97a7e; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-canceltryout-GETapi-v1-incidents"
                    onclick="cancelTryOut('GETapi-v1-incidents');" hidden>Cancel 🛑
            </button>&nbsp;&nbsp;
            <button type="submit"
                    style="background-color: #6ac174; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-executetryout-GETapi-v1-incidents"
                    data-initial-text="Send Request 💥"
                    data-loading-text="⏱ Sending..."
                    hidden>Send Request 💥
            </button>
            </h3>
            <p>
            <small class="badge badge-green">GET</small>
            <b><code>api/v1/incidents</code></b>
        </p>
                <h4 class="fancy-heading-panel"><b>Headers</b></h4>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Authorization</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Authorization" class="auth-value"               data-endpoint="GETapi-v1-incidents"
               value="Bearer {YOUR_AUTH_KEY}"
               data-component="header">
    <br>
<p>Example: <code>Bearer {YOUR_AUTH_KEY}</code></p>
            </div>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Content-Type</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Content-Type"                data-endpoint="GETapi-v1-incidents"
               value="application/json"
               data-component="header">
    <br>
<p>Example: <code>application/json</code></p>
            </div>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Accept</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Accept"                data-endpoint="GETapi-v1-incidents"
               value="application/json"
               data-component="header">
    <br>
<p>Example: <code>application/json</code></p>
            </div>
                            <h4 class="fancy-heading-panel"><b>Query Parameters</b></h4>
                                    <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>start_date</code></b>&nbsp;&nbsp;
<small>string</small>&nbsp;
<i>optional</i> &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="start_date"                data-endpoint="GETapi-v1-incidents"
               value="2024-01-01"
               data-component="query">
    <br>
<p>date Filter incidents from this date (inclusive). Example: <code>2024-01-01</code></p>
            </div>
                                    <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>end_date</code></b>&nbsp;&nbsp;
<small>string</small>&nbsp;
<i>optional</i> &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="end_date"                data-endpoint="GETapi-v1-incidents"
               value="2024-12-31"
               data-component="query">
    <br>
<p>date Filter incidents until this date (inclusive). Example: <code>2024-12-31</code></p>
            </div>
                                    <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>min_fund_loss</code></b>&nbsp;&nbsp;
<small>number</small>&nbsp;
<i>optional</i> &nbsp;
 &nbsp;
                <input type="number" style="display: none"
               step="any"               name="min_fund_loss"                data-endpoint="GETapi-v1-incidents"
               value="1000000"
               data-component="query">
    <br>
<p>Filter incidents with fund loss greater than or equal to this value. Example: <code>1000000</code></p>
            </div>
                                    <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>max_fund_loss</code></b>&nbsp;&nbsp;
<small>number</small>&nbsp;
<i>optional</i> &nbsp;
 &nbsp;
                <input type="number" style="display: none"
               step="any"               name="max_fund_loss"                data-endpoint="GETapi-v1-incidents"
               value="50000000"
               data-component="query">
    <br>
<p>Filter incidents with fund loss less than or equal to this value. Example: <code>50000000</code></p>
            </div>
                                    <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>min_potential_fund_loss</code></b>&nbsp;&nbsp;
<small>number</small>&nbsp;
<i>optional</i> &nbsp;
 &nbsp;
                <input type="number" style="display: none"
               step="any"               name="min_potential_fund_loss"                data-endpoint="GETapi-v1-incidents"
               value="1000000"
               data-component="query">
    <br>
<p>Filter incidents with potential loss greater than or equal to this value. Example: <code>1000000</code></p>
            </div>
                                    <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>max_potential_fund_loss</code></b>&nbsp;&nbsp;
<small>number</small>&nbsp;
<i>optional</i> &nbsp;
 &nbsp;
                <input type="number" style="display: none"
               step="any"               name="max_potential_fund_loss"                data-endpoint="GETapi-v1-incidents"
               value="100000000"
               data-component="query">
    <br>
<p>Filter incidents with potential loss less than or equal to this value. Example: <code>100000000</code></p>
            </div>
                                    <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>tags</code></b>&nbsp;&nbsp;
<small>string</small>&nbsp;
<i>optional</i> &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="tags"                data-endpoint="GETapi-v1-incidents"
               value="payment,database,timeout"
               data-component="query">
    <br>
<p>Filter by comma-separated label names. Example: <code>payment,database,timeout</code></p>
            </div>
                                    <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>type</code></b>&nbsp;&nbsp;
<small>string</small>&nbsp;
<i>optional</i> &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="type"                data-endpoint="GETapi-v1-incidents"
               value="Tech"
               data-component="query">
    <br>
<p>Filter by incident type. Must be &quot;Tech&quot; or &quot;Non-tech&quot;. Example: <code>Tech</code></p>
            </div>
                                    <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>page</code></b>&nbsp;&nbsp;
<small>integer</small>&nbsp;
<i>optional</i> &nbsp;
 &nbsp;
                <input type="number" style="display: none"
               step="any"               name="page"                data-endpoint="GETapi-v1-incidents"
               value="1"
               data-component="query">
    <br>
<p>Page number for pagination. Default: 1. Example: <code>1</code></p>
            </div>
                </form>

                    <h2 id="incidents-GETapi-v1-incidents--id-">Get incident by ID</h2>

<p>
<small class="badge badge-darkred">requires authentication</small>
</p>

<p>Retrieve detailed information about a specific incident by its database ID.
Includes all related data: PIC, status updates, investigation documents, labels, and action improvements.</p>

<span id="example-requests-GETapi-v1-incidents--id-">
<blockquote>Example request:</blockquote>


<div class="bash-example">
    <pre><code class="language-bash">curl --request GET \
    --get "http://localhost:8000/api/v1/incidents/1" \
    --header "Authorization: Bearer {YOUR_AUTH_KEY}" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json"</code></pre></div>


<div class="javascript-example">
    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/v1/incidents/1"
);

const headers = {
    "Authorization": "Bearer {YOUR_AUTH_KEY}",
    "Content-Type": "application/json",
    "Accept": "application/json",
};

fetch(url, {
    method: "GET",
    headers,
}).then(response =&gt; response.json());</code></pre></div>

</span>

<span id="example-responses-GETapi-v1-incidents--id-">
            <blockquote>
            <p>Example response (200):</p>
        </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;code&quot;: 200,
    &quot;status&quot;: &quot;Success&quot;,
    &quot;message&quot;: &quot;Incident retrieved successfully.&quot;,
    &quot;data&quot;: {
        &quot;id&quot;: 1,
        &quot;no&quot;: &quot;20250115_IN_1234&quot;,
        &quot;title&quot;: &quot;Payment Gateway Timeout&quot;,
        &quot;summary&quot;: &quot;5-minute outage during peak hours...&quot;,
        &quot;root_cause&quot;: &quot;Database connection pool exhausted due to high traffic&quot;,
        &quot;severity&quot;: &quot;P1&quot;,
        &quot;incident_type&quot;: &quot;Tech&quot;,
        &quot;incident_source&quot;: &quot;Internal&quot;,
        &quot;incident_date&quot;: &quot;2025-01-15T10:30:00.000000Z&quot;,
        &quot;fund_loss&quot;: 5000000,
        &quot;potential_fund_loss&quot;: 15000000,
        &quot;pic&quot;: {
            &quot;id&quot;: 5,
            &quot;name&quot;: &quot;John Doe&quot;,
            &quot;email&quot;: &quot;john.doe@company.com&quot;
        },
        &quot;labels&quot;: [
            {
                &quot;id&quot;: 1,
                &quot;name&quot;: &quot;payment&quot;
            },
            {
                &quot;id&quot;: 2,
                &quot;name&quot;: &quot;database&quot;
            }
        ],
        &quot;status_updates&quot;: [
            {
                &quot;id&quot;: 1,
                &quot;status&quot;: &quot;In progress&quot;,
                &quot;notes&quot;: &quot;Investigating database connection pool settings&quot;,
                &quot;updated_at&quot;: &quot;2025-01-15T11:00:00.000000Z&quot;
            }
        ],
        &quot;action_improvements&quot;: [
            {
                &quot;id&quot;: 1,
                &quot;title&quot;: &quot;Increase connection pool size&quot;,
                &quot;detail&quot;: &quot;Configure pool to handle 2x peak traffic&quot;,
                &quot;status&quot;: &quot;pending&quot;,
                &quot;due_date&quot;: &quot;2025-01-20&quot;
            }
        ]
    }
}</code>
 </pre>
            <blockquote>
            <p>Example response (404):</p>
        </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;code&quot;: 404,
    &quot;status&quot;: &quot;Error&quot;,
    &quot;message&quot;: &quot;Incident not found.&quot;,
    &quot;data&quot;: null
}</code>
 </pre>
    </span>
<span id="execution-results-GETapi-v1-incidents--id-" hidden>
    <blockquote>Received response<span
                id="execution-response-status-GETapi-v1-incidents--id-"></span>:
    </blockquote>
    <pre class="json"><code id="execution-response-content-GETapi-v1-incidents--id-"
      data-empty-response-text="<Empty response>" style="max-height: 400px;"></code></pre>
</span>
<span id="execution-error-GETapi-v1-incidents--id-" hidden>
    <blockquote>Request failed with error:</blockquote>
    <pre><code id="execution-error-message-GETapi-v1-incidents--id-">

Tip: Check that you&#039;re properly connected to the network.
If you&#039;re a maintainer of ths API, verify that your API is running and you&#039;ve enabled CORS.
You can check the Dev Tools console for debugging information.</code></pre>
</span>
<form id="form-GETapi-v1-incidents--id-" data-method="GET"
      data-path="api/v1/incidents/{id}"
      data-authed="1"
      data-hasfiles="0"
      data-isarraybody="0"
      autocomplete="off"
      onsubmit="event.preventDefault(); executeTryOut('GETapi-v1-incidents--id-', this);">
    <h3>
        Request&nbsp;&nbsp;&nbsp;
                    <button type="button"
                    style="background-color: #8fbcd4; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-tryout-GETapi-v1-incidents--id-"
                    onclick="tryItOut('GETapi-v1-incidents--id-');">Try it out ⚡
            </button>
            <button type="button"
                    style="background-color: #c97a7e; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-canceltryout-GETapi-v1-incidents--id-"
                    onclick="cancelTryOut('GETapi-v1-incidents--id-');" hidden>Cancel 🛑
            </button>&nbsp;&nbsp;
            <button type="submit"
                    style="background-color: #6ac174; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-executetryout-GETapi-v1-incidents--id-"
                    data-initial-text="Send Request 💥"
                    data-loading-text="⏱ Sending..."
                    hidden>Send Request 💥
            </button>
            </h3>
            <p>
            <small class="badge badge-green">GET</small>
            <b><code>api/v1/incidents/{id}</code></b>
        </p>
                <h4 class="fancy-heading-panel"><b>Headers</b></h4>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Authorization</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Authorization" class="auth-value"               data-endpoint="GETapi-v1-incidents--id-"
               value="Bearer {YOUR_AUTH_KEY}"
               data-component="header">
    <br>
<p>Example: <code>Bearer {YOUR_AUTH_KEY}</code></p>
            </div>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Content-Type</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Content-Type"                data-endpoint="GETapi-v1-incidents--id-"
               value="application/json"
               data-component="header">
    <br>
<p>Example: <code>application/json</code></p>
            </div>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Accept</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Accept"                data-endpoint="GETapi-v1-incidents--id-"
               value="application/json"
               data-component="header">
    <br>
<p>Example: <code>application/json</code></p>
            </div>
                        <h4 class="fancy-heading-panel"><b>URL Parameters</b></h4>
                    <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>id</code></b>&nbsp;&nbsp;
<small>integer</small>&nbsp;
 &nbsp;
 &nbsp;
                <input type="number" style="display: none"
               step="any"               name="id"                data-endpoint="GETapi-v1-incidents--id-"
               value="1"
               data-component="url">
    <br>
<p>The ID of the incident. Example: <code>1</code></p>
            </div>
                    </form>

                    <h2 id="incidents-GETapi-v1-incidents-by-no--no-">Get incident by incident number</h2>

<p>
<small class="badge badge-darkred">requires authentication</small>
</p>

<p>Retrieve detailed information about a specific incident by its incident number (e.g., &quot;20250115_IN_1234&quot;).
Includes all related data: PIC, status updates, investigation documents, labels, and action improvements.</p>

<span id="example-requests-GETapi-v1-incidents-by-no--no-">
<blockquote>Example request:</blockquote>


<div class="bash-example">
    <pre><code class="language-bash">curl --request GET \
    --get "http://localhost:8000/api/v1/incidents-by-no/20250115_IN_1234" \
    --header "Authorization: Bearer {YOUR_AUTH_KEY}" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json"</code></pre></div>


<div class="javascript-example">
    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/v1/incidents-by-no/20250115_IN_1234"
);

const headers = {
    "Authorization": "Bearer {YOUR_AUTH_KEY}",
    "Content-Type": "application/json",
    "Accept": "application/json",
};

fetch(url, {
    method: "GET",
    headers,
}).then(response =&gt; response.json());</code></pre></div>

</span>

<span id="example-responses-GETapi-v1-incidents-by-no--no-">
            <blockquote>
            <p>Example response (200):</p>
        </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;code&quot;: 200,
    &quot;status&quot;: &quot;Success&quot;,
    &quot;message&quot;: &quot;Incident retrieved successfully.&quot;,
    &quot;data&quot;: {
        &quot;id&quot;: 1,
        &quot;no&quot;: &quot;20250115_IN_1234&quot;,
        &quot;title&quot;: &quot;Payment Gateway Timeout&quot;,
        &quot;summary&quot;: &quot;5-minute outage during peak hours...&quot;,
        &quot;root_cause&quot;: &quot;Database connection pool exhausted due to high traffic&quot;,
        &quot;severity&quot;: &quot;P1&quot;,
        &quot;incident_type&quot;: &quot;Tech&quot;,
        &quot;incident_date&quot;: &quot;2025-01-15T10:30:00.000000Z&quot;,
        &quot;fund_loss&quot;: 5000000
    }
}</code>
 </pre>
            <blockquote>
            <p>Example response (404):</p>
        </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;code&quot;: 404,
    &quot;status&quot;: &quot;Error&quot;,
    &quot;message&quot;: &quot;Incident not found.&quot;,
    &quot;data&quot;: null
}</code>
 </pre>
    </span>
<span id="execution-results-GETapi-v1-incidents-by-no--no-" hidden>
    <blockquote>Received response<span
                id="execution-response-status-GETapi-v1-incidents-by-no--no-"></span>:
    </blockquote>
    <pre class="json"><code id="execution-response-content-GETapi-v1-incidents-by-no--no-"
      data-empty-response-text="<Empty response>" style="max-height: 400px;"></code></pre>
</span>
<span id="execution-error-GETapi-v1-incidents-by-no--no-" hidden>
    <blockquote>Request failed with error:</blockquote>
    <pre><code id="execution-error-message-GETapi-v1-incidents-by-no--no-">

Tip: Check that you&#039;re properly connected to the network.
If you&#039;re a maintainer of ths API, verify that your API is running and you&#039;ve enabled CORS.
You can check the Dev Tools console for debugging information.</code></pre>
</span>
<form id="form-GETapi-v1-incidents-by-no--no-" data-method="GET"
      data-path="api/v1/incidents-by-no/{no}"
      data-authed="1"
      data-hasfiles="0"
      data-isarraybody="0"
      autocomplete="off"
      onsubmit="event.preventDefault(); executeTryOut('GETapi-v1-incidents-by-no--no-', this);">
    <h3>
        Request&nbsp;&nbsp;&nbsp;
                    <button type="button"
                    style="background-color: #8fbcd4; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-tryout-GETapi-v1-incidents-by-no--no-"
                    onclick="tryItOut('GETapi-v1-incidents-by-no--no-');">Try it out ⚡
            </button>
            <button type="button"
                    style="background-color: #c97a7e; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-canceltryout-GETapi-v1-incidents-by-no--no-"
                    onclick="cancelTryOut('GETapi-v1-incidents-by-no--no-');" hidden>Cancel 🛑
            </button>&nbsp;&nbsp;
            <button type="submit"
                    style="background-color: #6ac174; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-executetryout-GETapi-v1-incidents-by-no--no-"
                    data-initial-text="Send Request 💥"
                    data-loading-text="⏱ Sending..."
                    hidden>Send Request 💥
            </button>
            </h3>
            <p>
            <small class="badge badge-green">GET</small>
            <b><code>api/v1/incidents-by-no/{no}</code></b>
        </p>
                <h4 class="fancy-heading-panel"><b>Headers</b></h4>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Authorization</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Authorization" class="auth-value"               data-endpoint="GETapi-v1-incidents-by-no--no-"
               value="Bearer {YOUR_AUTH_KEY}"
               data-component="header">
    <br>
<p>Example: <code>Bearer {YOUR_AUTH_KEY}</code></p>
            </div>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Content-Type</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Content-Type"                data-endpoint="GETapi-v1-incidents-by-no--no-"
               value="application/json"
               data-component="header">
    <br>
<p>Example: <code>application/json</code></p>
            </div>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Accept</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Accept"                data-endpoint="GETapi-v1-incidents-by-no--no-"
               value="application/json"
               data-component="header">
    <br>
<p>Example: <code>application/json</code></p>
            </div>
                        <h4 class="fancy-heading-panel"><b>URL Parameters</b></h4>
                    <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>no</code></b>&nbsp;&nbsp;
<small>string</small>&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="no"                data-endpoint="GETapi-v1-incidents-by-no--no-"
               value="20250115_IN_1234"
               data-component="url">
    <br>
<p>The incident number (format: YYYYMD_IN_XXXX or YYYYMD_IS_XXXX). Example: <code>20250115_IN_1234</code></p>
            </div>
                    </form>

                    <h2 id="incidents-GETapi-v1-incidents-by-no--no--markdown">Export incident as Markdown</h2>

<p>
<small class="badge badge-darkred">requires authentication</small>
</p>

<p>Retrieve an incident formatted as Markdown document.
Includes all related data formatted in a readable Markdown structure.
Useful for documentation, reporting, and AI ingestion.</p>

<span id="example-requests-GETapi-v1-incidents-by-no--no--markdown">
<blockquote>Example request:</blockquote>


<div class="bash-example">
    <pre><code class="language-bash">curl --request GET \
    --get "http://localhost:8000/api/v1/incidents-by-no/20250115_IN_1234/markdown" \
    --header "Authorization: Bearer {YOUR_AUTH_KEY}" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json"</code></pre></div>


<div class="javascript-example">
    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/v1/incidents-by-no/20250115_IN_1234/markdown"
);

const headers = {
    "Authorization": "Bearer {YOUR_AUTH_KEY}",
    "Content-Type": "application/json",
    "Accept": "application/json",
};

fetch(url, {
    method: "GET",
    headers,
}).then(response =&gt; response.json());</code></pre></div>

</span>

<span id="example-responses-GETapi-v1-incidents-by-no--no--markdown">
            <blockquote>
            <p>Example response (200):</p>
        </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
  &quot;# Payment Gateway Timeout&quot;,
  &quot;&quot;,
  &quot;**Incident ID:** 20250115_IN_1234&quot;,
  &quot;&quot;,
  &quot;## Basic Information&quot;,
  &quot;&quot;,
  &quot;| Field | Value |&quot;,
  &quot;|-------|-------|&quot;,
  &quot;| **Severity** | P1 |&quot;,
  &quot;| **Type** | Tech |&quot;,
  &quot;| **Source** | Internal |&quot;,
  &quot;&quot;,
  &quot;## Summary&quot;,
  &quot;&quot;,
  &quot;5-minute outage during peak hours...&quot;,
  &quot;&quot;,
  &quot;## Root Cause&quot;,
  &quot;&quot;,
  &quot;Database connection pool exhausted due to high traffic&quot;,
  &quot;&quot;
}</code>
 </pre>
            <blockquote>
            <p>Example response (404):</p>
        </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;code&quot;: 404,
    &quot;status&quot;: &quot;Error&quot;,
    &quot;message&quot;: &quot;Incident not found.&quot;,
    &quot;data&quot;: null
}</code>
 </pre>
    </span>
<span id="execution-results-GETapi-v1-incidents-by-no--no--markdown" hidden>
    <blockquote>Received response<span
                id="execution-response-status-GETapi-v1-incidents-by-no--no--markdown"></span>:
    </blockquote>
    <pre class="json"><code id="execution-response-content-GETapi-v1-incidents-by-no--no--markdown"
      data-empty-response-text="<Empty response>" style="max-height: 400px;"></code></pre>
</span>
<span id="execution-error-GETapi-v1-incidents-by-no--no--markdown" hidden>
    <blockquote>Request failed with error:</blockquote>
    <pre><code id="execution-error-message-GETapi-v1-incidents-by-no--no--markdown">

Tip: Check that you&#039;re properly connected to the network.
If you&#039;re a maintainer of ths API, verify that your API is running and you&#039;ve enabled CORS.
You can check the Dev Tools console for debugging information.</code></pre>
</span>
<form id="form-GETapi-v1-incidents-by-no--no--markdown" data-method="GET"
      data-path="api/v1/incidents-by-no/{no}/markdown"
      data-authed="1"
      data-hasfiles="0"
      data-isarraybody="0"
      autocomplete="off"
      onsubmit="event.preventDefault(); executeTryOut('GETapi-v1-incidents-by-no--no--markdown', this);">
    <h3>
        Request&nbsp;&nbsp;&nbsp;
                    <button type="button"
                    style="background-color: #8fbcd4; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-tryout-GETapi-v1-incidents-by-no--no--markdown"
                    onclick="tryItOut('GETapi-v1-incidents-by-no--no--markdown');">Try it out ⚡
            </button>
            <button type="button"
                    style="background-color: #c97a7e; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-canceltryout-GETapi-v1-incidents-by-no--no--markdown"
                    onclick="cancelTryOut('GETapi-v1-incidents-by-no--no--markdown');" hidden>Cancel 🛑
            </button>&nbsp;&nbsp;
            <button type="submit"
                    style="background-color: #6ac174; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-executetryout-GETapi-v1-incidents-by-no--no--markdown"
                    data-initial-text="Send Request 💥"
                    data-loading-text="⏱ Sending..."
                    hidden>Send Request 💥
            </button>
            </h3>
            <p>
            <small class="badge badge-green">GET</small>
            <b><code>api/v1/incidents-by-no/{no}/markdown</code></b>
        </p>
                <h4 class="fancy-heading-panel"><b>Headers</b></h4>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Authorization</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Authorization" class="auth-value"               data-endpoint="GETapi-v1-incidents-by-no--no--markdown"
               value="Bearer {YOUR_AUTH_KEY}"
               data-component="header">
    <br>
<p>Example: <code>Bearer {YOUR_AUTH_KEY}</code></p>
            </div>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Content-Type</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Content-Type"                data-endpoint="GETapi-v1-incidents-by-no--no--markdown"
               value="application/json"
               data-component="header">
    <br>
<p>Example: <code>application/json</code></p>
            </div>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Accept</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Accept"                data-endpoint="GETapi-v1-incidents-by-no--no--markdown"
               value="application/json"
               data-component="header">
    <br>
<p>Example: <code>application/json</code></p>
            </div>
                        <h4 class="fancy-heading-panel"><b>URL Parameters</b></h4>
                    <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>no</code></b>&nbsp;&nbsp;
<small>string</small>&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="no"                data-endpoint="GETapi-v1-incidents-by-no--no--markdown"
               value="20250115_IN_1234"
               data-component="url">
    <br>
<p>The incident number. Example: <code>20250115_IN_1234</code></p>
            </div>
                    </form>

                    <h2 id="incidents-GETapi-v1-labels">Get all labels</h2>

<p>
<small class="badge badge-darkred">requires authentication</small>
</p>

<p>Retrieve a list of all available labels/tags used for categorizing incidents.
Results are cached for 60 minutes.</p>

<span id="example-requests-GETapi-v1-labels">
<blockquote>Example request:</blockquote>


<div class="bash-example">
    <pre><code class="language-bash">curl --request GET \
    --get "http://localhost:8000/api/v1/labels" \
    --header "Authorization: Bearer {YOUR_AUTH_KEY}" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json"</code></pre></div>


<div class="javascript-example">
    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/v1/labels"
);

const headers = {
    "Authorization": "Bearer {YOUR_AUTH_KEY}",
    "Content-Type": "application/json",
    "Accept": "application/json",
};

fetch(url, {
    method: "GET",
    headers,
}).then(response =&gt; response.json());</code></pre></div>

</span>

<span id="example-responses-GETapi-v1-labels">
            <blockquote>
            <p>Example response (200):</p>
        </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;code&quot;: 200,
    &quot;status&quot;: &quot;Success&quot;,
    &quot;message&quot;: &quot;Labels retrieved successfully.&quot;,
    &quot;data&quot;: [
        &quot;payment&quot;,
        &quot;database&quot;,
        &quot;timeout&quot;,
        &quot;network&quot;,
        &quot;server&quot;,
        &quot;api&quot;
    ]
}</code>
 </pre>
    </span>
<span id="execution-results-GETapi-v1-labels" hidden>
    <blockquote>Received response<span
                id="execution-response-status-GETapi-v1-labels"></span>:
    </blockquote>
    <pre class="json"><code id="execution-response-content-GETapi-v1-labels"
      data-empty-response-text="<Empty response>" style="max-height: 400px;"></code></pre>
</span>
<span id="execution-error-GETapi-v1-labels" hidden>
    <blockquote>Request failed with error:</blockquote>
    <pre><code id="execution-error-message-GETapi-v1-labels">

Tip: Check that you&#039;re properly connected to the network.
If you&#039;re a maintainer of ths API, verify that your API is running and you&#039;ve enabled CORS.
You can check the Dev Tools console for debugging information.</code></pre>
</span>
<form id="form-GETapi-v1-labels" data-method="GET"
      data-path="api/v1/labels"
      data-authed="1"
      data-hasfiles="0"
      data-isarraybody="0"
      autocomplete="off"
      onsubmit="event.preventDefault(); executeTryOut('GETapi-v1-labels', this);">
    <h3>
        Request&nbsp;&nbsp;&nbsp;
                    <button type="button"
                    style="background-color: #8fbcd4; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-tryout-GETapi-v1-labels"
                    onclick="tryItOut('GETapi-v1-labels');">Try it out ⚡
            </button>
            <button type="button"
                    style="background-color: #c97a7e; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-canceltryout-GETapi-v1-labels"
                    onclick="cancelTryOut('GETapi-v1-labels');" hidden>Cancel 🛑
            </button>&nbsp;&nbsp;
            <button type="submit"
                    style="background-color: #6ac174; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-executetryout-GETapi-v1-labels"
                    data-initial-text="Send Request 💥"
                    data-loading-text="⏱ Sending..."
                    hidden>Send Request 💥
            </button>
            </h3>
            <p>
            <small class="badge badge-green">GET</small>
            <b><code>api/v1/labels</code></b>
        </p>
                <h4 class="fancy-heading-panel"><b>Headers</b></h4>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Authorization</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Authorization" class="auth-value"               data-endpoint="GETapi-v1-labels"
               value="Bearer {YOUR_AUTH_KEY}"
               data-component="header">
    <br>
<p>Example: <code>Bearer {YOUR_AUTH_KEY}</code></p>
            </div>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Content-Type</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Content-Type"                data-endpoint="GETapi-v1-labels"
               value="application/json"
               data-component="header">
    <br>
<p>Example: <code>application/json</code></p>
            </div>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Accept</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Accept"                data-endpoint="GETapi-v1-labels"
               value="application/json"
               data-component="header">
    <br>
<p>Example: <code>application/json</code></p>
            </div>
                        </form>

                    <h2 id="incidents-GETapi-v1-incident-types">Get all incident types</h2>

<p>
<small class="badge badge-darkred">requires authentication</small>
</p>

<p>Retrieve a list of all available incident types.
Results are cached for 60 minutes.</p>

<span id="example-requests-GETapi-v1-incident-types">
<blockquote>Example request:</blockquote>


<div class="bash-example">
    <pre><code class="language-bash">curl --request GET \
    --get "http://localhost:8000/api/v1/incident-types" \
    --header "Authorization: Bearer {YOUR_AUTH_KEY}" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json"</code></pre></div>


<div class="javascript-example">
    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/v1/incident-types"
);

const headers = {
    "Authorization": "Bearer {YOUR_AUTH_KEY}",
    "Content-Type": "application/json",
    "Accept": "application/json",
};

fetch(url, {
    method: "GET",
    headers,
}).then(response =&gt; response.json());</code></pre></div>

</span>

<span id="example-responses-GETapi-v1-incident-types">
            <blockquote>
            <p>Example response (200):</p>
        </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;code&quot;: 200,
    &quot;status&quot;: &quot;Success&quot;,
    &quot;message&quot;: &quot;Incident types retrieved successfully.&quot;,
    &quot;data&quot;: [
        &quot;Network Issue&quot;,
        &quot;Server Error&quot;,
        &quot;Database Timeout&quot;,
        &quot;API Failure&quot;
    ]
}</code>
 </pre>
    </span>
<span id="execution-results-GETapi-v1-incident-types" hidden>
    <blockquote>Received response<span
                id="execution-response-status-GETapi-v1-incident-types"></span>:
    </blockquote>
    <pre class="json"><code id="execution-response-content-GETapi-v1-incident-types"
      data-empty-response-text="<Empty response>" style="max-height: 400px;"></code></pre>
</span>
<span id="execution-error-GETapi-v1-incident-types" hidden>
    <blockquote>Request failed with error:</blockquote>
    <pre><code id="execution-error-message-GETapi-v1-incident-types">

Tip: Check that you&#039;re properly connected to the network.
If you&#039;re a maintainer of ths API, verify that your API is running and you&#039;ve enabled CORS.
You can check the Dev Tools console for debugging information.</code></pre>
</span>
<form id="form-GETapi-v1-incident-types" data-method="GET"
      data-path="api/v1/incident-types"
      data-authed="1"
      data-hasfiles="0"
      data-isarraybody="0"
      autocomplete="off"
      onsubmit="event.preventDefault(); executeTryOut('GETapi-v1-incident-types', this);">
    <h3>
        Request&nbsp;&nbsp;&nbsp;
                    <button type="button"
                    style="background-color: #8fbcd4; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-tryout-GETapi-v1-incident-types"
                    onclick="tryItOut('GETapi-v1-incident-types');">Try it out ⚡
            </button>
            <button type="button"
                    style="background-color: #c97a7e; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-canceltryout-GETapi-v1-incident-types"
                    onclick="cancelTryOut('GETapi-v1-incident-types');" hidden>Cancel 🛑
            </button>&nbsp;&nbsp;
            <button type="submit"
                    style="background-color: #6ac174; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                    id="btn-executetryout-GETapi-v1-incident-types"
                    data-initial-text="Send Request 💥"
                    data-loading-text="⏱ Sending..."
                    hidden>Send Request 💥
            </button>
            </h3>
            <p>
            <small class="badge badge-green">GET</small>
            <b><code>api/v1/incident-types</code></b>
        </p>
                <h4 class="fancy-heading-panel"><b>Headers</b></h4>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Authorization</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Authorization" class="auth-value"               data-endpoint="GETapi-v1-incident-types"
               value="Bearer {YOUR_AUTH_KEY}"
               data-component="header">
    <br>
<p>Example: <code>Bearer {YOUR_AUTH_KEY}</code></p>
            </div>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Content-Type</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Content-Type"                data-endpoint="GETapi-v1-incident-types"
               value="application/json"
               data-component="header">
    <br>
<p>Example: <code>application/json</code></p>
            </div>
                                <div style="padding-left: 28px; clear: unset;">
                <b style="line-height: 2;"><code>Accept</code></b>&nbsp;&nbsp;
&nbsp;
 &nbsp;
 &nbsp;
                <input type="text" style="display: none"
                              name="Accept"                data-endpoint="GETapi-v1-incident-types"
               value="application/json"
               data-component="header">
    <br>
<p>Example: <code>application/json</code></p>
            </div>
                        </form>

            

        
    </div>
    <div class="dark-box">
                    <div class="lang-selector">
                                                        <button type="button" class="lang-button" data-language-name="bash">bash</button>
                                                        <button type="button" class="lang-button" data-language-name="javascript">javascript</button>
                            </div>
            </div>
</div>
</body>
</html>
