(function() {
    const BASE_PATH = "{{BASE_PATH}}";
    const SITE = "{{SITE}}";

    function handleResponse(response) {
        if (response.status === 201) {
            const inputs = document.querySelectorAll("div.cs-form .form-control");
            inputs.forEach(input => input.value = "");

            const element = document.querySelector(".cs-form-message");
            element.innerText = "{{successMessage}}";
            element.classList.remove("fail");
            element.classList.add("success");

            refresh();
        } else {
            const element = document.querySelector(".cs-form-message");
            response.json().then(json => {
                element.innerText = `{{failMessage}} ${json['message']}`;
            });
            element.classList.remove("success");
            element.classList.add("fail");
        }
    }
    function markInvalidFieldsAndIsValid() {
        let isValid = true;
        const inputs = document.querySelectorAll("div.cs-form .form-control");
        inputs.forEach(input => {
            if (input.value.trim().length === 0) {
                input.parentNode.classList.add("has-error");
                isValid = false;
            } else {
                input.parentNode.classList.remove("has-error");
            }
        });
        return isValid;
    }
    function submitComment() {
        if (!markInvalidFieldsAndIsValid()) {
            return false;
        }
        const author = document.querySelector("#cs-author").value;
        const email = document.querySelector("#cs-email").value;
        const content = document.querySelector("#cs-content").value;
        const url = document.querySelector("#cs-url").value;
        const payload = {
            author: author,
            email: email,
            content: content,
            site: SITE,
            path: location.pathname,
            url: url
        };
        fetch(BASE_PATH,
            {
                headers: {
                    'Content-Type': 'application/json'
                },
                method: "POST",
                body: JSON.stringify(payload)
            })
            .then(handleResponse);
        return false;
    }
    function createNodesForComments(comments) {
        if (comments.length === 0){
            const heading = document.createElement("p");
            heading.innerText = '{{noCommentsYet}}';
            return [heading];
        } else {
            return comments.map(createNodeForComment);
        }
    }
    function formatDate(creationTimestamp) {
        let creationDate = new Date(creationTimestamp * 1000);
        const agoAndUnit = getTimeSinceInBiggestUnit(creationDate);
        return "{{dateString}}".replace("{}", agoAndUnit);

    }
    function getTimeSinceInBiggestUnit(creationDate) {
        const seconds = Math.floor((new Date() - creationDate) / 1000);

        let interval = Math.floor(seconds / 31536000);
        if (interval > 1) return interval + " {{years}}";

        interval = Math.floor(seconds / 2592000);
        if (interval > 1) return interval + " {{months}}";

        interval = Math.floor(seconds / 86400);
        if (interval >= 1) return interval + " {{days}}";

        interval = Math.floor(seconds / 3600);
        if (interval >= 1) return interval + " {{hours}}";

        interval = Math.floor(seconds / 60);
        if (interval > 1) return interval + " {{minutes}}";

        return Math.floor(seconds) + " {{seconds}}";
    }
    function createNodeForComment(comment) {
        const postDiv = document.createElement('div');
        postDiv.setAttribute("class", "cs-post");
        postDiv.innerHTML = `
            <div class="cs-avatar"><img src="${comment.gravatarUrl}?s=65&d=mm"/></div>
            <div class="cs-body">
                <header class="cs-header">
                    <span class="cs-author">${comment.author}</span> 
                    <span class="cs-date">${formatDate(comment.creationTimestamp)}</span>
                </header>
                <div class="cs-content">${comment.content}</div>
            </div>
        `;
        return postDiv;
    }
    function createFormNode() {
        const div = document.createElement('div');
        div.setAttribute("class", "cs-form");
        div.innerHTML = `
            <form>
              <div class="form-group">
                <label for="cs-author" class="control-label">{{name}}:</label>
                <input type="text" class="form-control" id="cs-author">
              </div>
              <div class="form-group">
                <label for="cs-email" class="control-label">{{email}}:</label>
                <input type="email" class="form-control" id="cs-email" placeholder="{{emailHint}}">
              </div>
              <div class="form-group cs-url-group">
                <label for="cs-url" class="control-label">URL:</label>
                <input type="url" id="cs-url" name="url" placeholder="URL">
              </div>
              <div class="form-group">
                <label for="cs-content" class="control-label">{{comment}}:</label>
                <textarea class="form-control" id="cs-content" rows="7"></textarea>
              </div>
              <button type="submit" class="btn btn-primary">{{submit}}</button>
              <p class="cs-form-message"></p>
            </form>
        `;
        div.querySelector("button").onclick = submitComment;
        return div;
    }
    function loadComments(){
        const path = encodeURIComponent(location.pathname);
        return fetch(`${BASE_PATH}?site=${SITE}&path=${path}`)
            .then(response => response.json())
            .then(createNodesForComments);
    }

    const commentAreaNode = document.querySelector("#comment-sidecar");
    commentAreaNode.innerHTML = `<h1>{{comments}}</h1>`;

    commentAreaNode.appendChild(createFormNode());

    const commentListNode = document.createElement("div");
    commentListNode.className = 'cs-comment-list';
    commentAreaNode.appendChild(commentListNode);

    const refresh = () => {
        commentListNode.innerHTML = '';
        loadComments().then(commentDomNodes => {
            commentDomNodes.forEach(node => commentListNode.appendChild(node));
        });
    };
    refresh();
})();