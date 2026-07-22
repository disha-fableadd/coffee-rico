<html>
    <head>
        <meta name="referrer" content="unsafe-url">
    </head>
    <body>
        <iframe id="clinicForm" src="https://referral.clin-sync.com/contact/32/enquiry" width="1100" height="850" frameborder="0"></iframe>
           
        <script>
            document.addEventListener("DOMContentLoaded", function () {
            
                var iframe = document.getElementById("clinicForm");
            
                var baseSrc = iframe.getAttribute("src").split("?")[0];
            
                var currentPageURL = encodeURIComponent(window.location.href);
            
                iframe.src = baseSrc + "?parent_url=" + currentPageURL;
            });
            </script>
    </body>
</html>