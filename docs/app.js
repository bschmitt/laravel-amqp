const { createApp } = Vue;

// --- Marked renderer: wrap every fenced code block with a copy button overlay ---
function escapeHtmlForCode(str) {
  return String(str)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

if (typeof marked !== "undefined") {
  marked.use({
    renderer: {
      // Handles both modern (token object) and legacy (positional args) Marked APIs.
      code(tokenOrText, langArg, escapedArg) {
        let text;
        let lang;
        let isEscaped = false;

        if (typeof tokenOrText === "object" && tokenOrText !== null) {
          text = tokenOrText.text;
          lang = (tokenOrText.lang || "").trim().split(/\s+/)[0];
          isEscaped = !!tokenOrText.escaped;
        } else {
          text = tokenOrText;
          lang = (langArg || "").trim().split(/\s+/)[0];
          isEscaped = !!escapedArg;
        }

        const safeCode = isEscaped ? text : escapeHtmlForCode(text || "");
        const langClass = lang ? ' class="language-' + lang + '"' : "";
        const langBadge = lang
          ? '<span class="code-block-lang" aria-hidden="true">' + lang + "</span>"
          : "";

        const copyBtn =
          '<button type="button" class="code-copy-btn" aria-label="Copy code to clipboard">' +
            '<svg class="copy-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
              '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>' +
              '<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>' +
            "</svg>" +
            '<svg class="check-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
              '<polyline points="20 6 9 17 4 12"/>' +
            "</svg>" +
            '<span class="copy-label">Copy</span>' +
          "</button>";

        return (
          '<div class="code-block-wrapper">' +
            langBadge +
            copyBtn +
            "<pre><code" + langClass + ">" + safeCode + "</code></pre>" +
          "</div>"
        );
      },
    },
  });
}

createApp({
  data() {
    return {
      currentPage: "home",
      activeTab: "install",
      searchQuery: "",
      isDark: false,
      mobileMenuOpen: false,
      docNavOpen: false,
      documentation: {},
      searchOpen: false,
      searchResults: [],
      selectedResultIndex: 0,
      contributors: [],
      loadingContributors: true,
      quickStartTabRefs: {},
      quickStartTabs: [
        {
          id: "install",
          label: "Installation",
          description: "Install via Composer & publish config",
          icon: "",
          language: "bash",
          code:
            "# Install via Composer\n" +
            "composer require bschmitt/laravel-amqp\n\n" +
            "# Publish configuration\n" +
            'php artisan vendor:publish --provider="Bschmitt\\Amqp\\Providers\\AmqpServiceProvider"',
        },
        {
          id: "publish",
          label: "Publishing",
          description: "Send messages with optional properties",
          icon: "",
          language: "php",
          code:
            "use Bschmitt\\Amqp\\Facades\\Amqp;\n\n" +
            "// Simple publish\n" +
            "Amqp::publish('routing-key', 'Hello World');\n\n" +
            "// Publish with properties\n" +
            "Amqp::publish('routing-key', 'Message', [\n" +
            "    'priority' => 10,\n" +
            "    'correlation_id' => 'unique-id',\n" +
            "    'application_headers' => [\n" +
            "        'X-Custom-Header' => 'value'\n" +
            "    ]\n" +
            "]);",
        },
        {
          id: "consume",
          label: "Consuming",
          description: "Pull and acknowledge messages",
          icon: "",
          language: "php",
          code:
            "use Bschmitt\\Amqp\\Facades\\Amqp;\n\n" +
            "$amqp = app('Amqp');\n" +
            "$amqp->consume('queue-name', function ($message, $resolver) {\n" +
            "    // Process message\n" +
            "    echo $message->body;\n\n" +
            "    // Acknowledge\n" +
            "    $resolver->acknowledge($message);\n" +
            "    $resolver->stopWhenProcessed();\n" +
            "});",
        },
        {
          id: "queue",
          label: "Queue Driver",
          description: "Use Laravel's dispatch() with RabbitMQ",
          icon: "",
          language: "php",
          code:
            "// .env\n" +
            "QUEUE_CONNECTION=amqp\n\n" +
            "// config/queue.php — merge from config/queue-amqp.php\n" +
            "'amqp' => [\n" +
            "    'driver'     => 'amqp',\n" +
            "    'connection' => env('AMQP_ENV', 'production'),\n" +
            "    'queue'      => env('AMQP_QUEUE', 'default'),\n" +
            "],\n\n" +
            "// Dispatch & work\n" +
            "ProcessOrder::dispatch($order)->delay(now()->addMinutes(5));\n" +
            "php artisan queue:work amqp --queue=default",
        },
        {
          id: "artisan",
          label: "Artisan Commands",
          description: "Run workers and publish messages from the CLI",
          icon: "",
          language: "bash",
          code:
            "# Long-running worker with QoS and memory cap\n" +
            "php artisan amqp:work orders \\\n" +
            "    --handler=\"App\\Messaging\\ProcessOrderHandler\" \\\n" +
            "    --prefetch-count=10 \\\n" +
            "    --memory=256\n\n" +
            "# Drain N messages then exit (great for cron)\n" +
            "php artisan amqp:consume orders \\\n" +
            "    --handler=\"App\\Messaging\\ProcessOrderHandler\" \\\n" +
            "    --max-messages=100\n\n" +
            "# Listen on routing keys with an auto-deleted queue\n" +
            "php artisan amqp:listen order.created order.updated \\\n" +
            "    --handler=\"App\\Messaging\\OrderHandler\"\n\n" +
            "# Publish from the CLI for smoke tests\n" +
            "php artisan amqp:publish order.created \\\n" +
            "    --body='{\"id\":42}' --exchange=orders --priority=5\n\n" +
            "# Wipe a queue (use --force to skip confirmation)\n" +
            "php artisan amqp:purge dead-letters --force",
        },
      ],
    };
  },
  computed: {
    currentContent() {
      const page = this.currentPage;

      if (this.documentation[page]) {
        return marked.parse(this.documentation[page]);
      }

      return "<h1>Page Not Found</h1><p>The requested page could not be found.</p>";
    },
    filteredSearchResults() {
      if (!this.searchQuery.trim()) {
        return [];
      }

      const query = this.searchQuery.toLowerCase();
      const results = [];

      // Search through all documentation
      Object.keys(this.documentation).forEach((pageKey) => {
        const content = this.documentation[pageKey];
        const lines = content.split("\n");

        // Search in headings and content
        lines.forEach((line, index) => {
          if (line.toLowerCase().includes(query)) {
            // Extract heading or context
            let title = pageKey.replace(/-/g, " ");
            title = title.charAt(0).toUpperCase() + title.slice(1);

            let snippet = line.trim();
            // If it's a heading, use it as title
            if (line.startsWith("#")) {
              snippet = line.replace(/^#+\s*/, "");
            }

            // Limit snippet length
            if (snippet.length > 100) {
              snippet = snippet.substring(0, 100) + "...";
            }

            results.push({
              page: pageKey,
              title: title,
              snippet: snippet,
              line: index,
            });
          }
        });
      });

      return results.slice(0, 10); // Limit to 10 results
    },
  },
  watch: {
    currentPage(newPage) {
      window.scrollTo({ top: 0, behavior: "smooth" });

      this.$nextTick(() => {
        if (typeof Prism !== "undefined") {
          Prism.highlightAll();
        }
      });

      this.syncHashToPage(newPage);
    },
    activeTab(newTab) {
      this.$nextTick(() => {
        if (typeof Prism !== "undefined") {
          Prism.highlightAll();
        }
        this.ensureActiveTabVisible(newTab);
      });
    },
    searchQuery() {
      this.selectedResultIndex = 0;
    },
  },
  async mounted() {
    try {
      await this.loadDocumentation();

      const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
      this.isDark =
        localStorage.getItem("theme") === "dark" ||
        (prefersDark && !localStorage.getItem("theme"));
      this.applyTheme();

      if (typeof Prism !== "undefined") {
        Prism.highlightAll();
      }

      this.applyHash();
      window.addEventListener("hashchange", this.applyHash);

      document.addEventListener("keydown", this.handleKeyboard);
      document.addEventListener("click", this.handleCopyClick);

      this.fetchContributors();
    } catch (error) {
      console.error("Error in mounted hook:", error);
      this.fetchContributors();
    }
  },
  beforeUnmount() {
    document.removeEventListener("keydown", this.handleKeyboard);
    document.removeEventListener("click", this.handleCopyClick);
    window.removeEventListener("hashchange", this.applyHash);
  },
  methods: {
    handleKeyboard(e) {
      // Ctrl+K or Cmd+K to open search
      if ((e.ctrlKey || e.metaKey) && e.key === "k") {
        e.preventDefault();
        this.toggleSearch();
        return;
      }

      // Escape to close search
      if (e.key === "Escape" && this.searchOpen) {
        e.preventDefault();
        this.closeSearch();
        return;
      }

      // Arrow keys to navigate results
      if (this.searchOpen && this.filteredSearchResults.length > 0) {
        if (e.key === "ArrowDown") {
          e.preventDefault();
          this.selectedResultIndex = Math.min(
            this.selectedResultIndex + 1,
            this.filteredSearchResults.length - 1
          );
        } else if (e.key === "ArrowUp") {
          e.preventDefault();
          this.selectedResultIndex = Math.max(this.selectedResultIndex - 1, 0);
        } else if (e.key === "Enter") {
          e.preventDefault();
          if (this.filteredSearchResults[this.selectedResultIndex]) {
            this.selectResult(this.filteredSearchResults[this.selectedResultIndex]);
          }
        }
      }
    },
    toggleSearch() {
      this.searchOpen = !this.searchOpen;
      if (this.searchOpen) {
        this.$nextTick(() => {
          const input = document.querySelector(".command-palette-input");
          if (input) input.focus();
        });
      } else {
        this.searchQuery = "";
      }
    },
    closeSearch() {
      this.searchOpen = false;
      this.searchQuery = "";
      this.selectedResultIndex = 0;
    },
    selectResult(result) {
      this.navigateTo(result.page);
      this.closeSearch();
    },
    navigateTo(page) {
      this.mobileMenuOpen = false;
      this.docNavOpen = false;

      if (this.currentPage === page) {
        window.scrollTo({ top: 0, behavior: "smooth" });
        return;
      }

      this.currentPage = page;
    },
    applyHash() {
      const hash = window.location.hash.replace(/^#/, "").trim();

      if (!hash || hash === "home") {
        if (this.currentPage !== "home") {
          this.currentPage = "home";
        }
        return;
      }

      if (this.documentation[hash]) {
        if (this.currentPage !== hash) {
          this.currentPage = hash;
        }
        return;
      }

      console.warn(`Unknown documentation page "${hash}" — redirecting to home.`);
      if (this.currentPage !== "home") {
        this.currentPage = "home";
      }
      history.replaceState(null, "", window.location.pathname + window.location.search);
    },
    ensureActiveTabVisible(tabId) {
      const nav = this.$refs.quickStartNav;
      const button = this.quickStartTabRefs[tabId];
      if (!nav || !button || typeof button.scrollIntoView !== "function") {
        return;
      }

      const navRect = nav.getBoundingClientRect();
      const btnRect = button.getBoundingClientRect();

      const offscreenHorizontally =
        btnRect.left < navRect.left || btnRect.right > navRect.right;
      const offscreenVertically =
        btnRect.top < navRect.top || btnRect.bottom > navRect.bottom;

      if (offscreenHorizontally || offscreenVertically) {
        button.scrollIntoView({
          behavior: "smooth",
          block: "nearest",
          inline: "center",
        });
      }
    },
    syncHashToPage(page) {
      const currentHash = window.location.hash.replace(/^#/, "");

      if (page === "home") {
        if (currentHash) {
          history.replaceState(null, "", window.location.pathname + window.location.search);
        }
        return;
      }

      if (currentHash !== page) {
        window.location.hash = page;
      }
    },
    toggleTheme() {
      this.isDark = !this.isDark;
      this.applyTheme();
      localStorage.setItem("theme", this.isDark ? "dark" : "light");
    },
    applyTheme() {
      if (this.isDark) {
        document.documentElement.setAttribute("data-theme", "dark");
      } else {
        document.documentElement.removeAttribute("data-theme");
      }
    },
    async fetchContributors() {
      try {
        const response = await fetch(
          "https://api.github.com/repos/bschmitt/laravel-amqp/contributors?per_page=3"
        );
        if (response.ok) {
          const contributorsList = await response.json();

          // Fetch detailed information for each user concurrently
          const detailedContributors = await Promise.all(
            contributorsList.map(async (c) => {
              try {
                const userResponse = await fetch(`https://api.github.com/users/${c.login}`);
                if (userResponse.ok) {
                  const userData = await userResponse.json();
                  return {
                    login: c.login,
                    avatar_url: c.avatar_url,
                    html_url: c.html_url,
                    contributions: c.contributions,
                    name: userData.name || c.login,
                    bio: userData.bio,
                    location: userData.location,
                    blog: userData.blog,
                    twitter: userData.twitter_username,
                    followers: userData.followers,
                    public_repos: userData.public_repos
                  };
                }
              } catch (e) {
                console.warn(`Failed to fetch details for ${c.login}:`, e);
              }
              // Return original data if detail fetch fails
              return {
                login: c.login,
                avatar_url: c.avatar_url,
                html_url: c.html_url,
                contributions: c.contributions,
              };
            })
          );

          this.contributors = detailedContributors;
        } else {
          throw new Error("Failed to fetch contributors list");
        }
      } catch (error) {
        console.error("Failed to fetch contributors:", error);
        // Fallback to static data
        this.contributors = [
          {
            login: "bschmitt",
            name: "Bernd Schmitt",
            avatar_url: "https://avatars.githubusercontent.com/u/239644?v=4",
            html_url: "https://github.com/bschmitt",
            location: 'Berlin',
            contributions: 55,
          },
          {
            login: "zfhassaan",
            name: "Hassaan",
            avatar_url: "https://avatars.githubusercontent.com/u/17079656?v=4",
            html_url: "https://github.com/zfhassaan",
            location: 'Pakistan',
            contributions: 53,
          },
          {
            login: "petekelly",
            name: "Pete Kelly",
            avatar_url: "https://avatars.githubusercontent.com/u/1177933?v=4",
            html_url: "https://github.com/petekelly",
            location: 'UK',
            contributions: 6,
          },
        ];
      } finally {
        this.loadingContributors = false;
      }
    },
    async handleCopyClick(event) {
      const btn = event.target.closest && event.target.closest(".code-copy-btn");
      if (!btn) return;
      event.preventDefault();

      const wrapper = btn.closest(".code-block-wrapper");
      if (!wrapper) return;
      const codeEl = wrapper.querySelector("pre code") || wrapper.querySelector("pre");
      if (!codeEl) return;

      const text = (codeEl.textContent || "").replace(/\n+$/, "");
      const ok = await this.copyToClipboard(text);
      if (!ok) return;

      btn.classList.add("copied");
      const label = btn.querySelector(".copy-label");
      if (label) label.textContent = "Copied!";

      if (btn._copyResetTimer) clearTimeout(btn._copyResetTimer);
      btn._copyResetTimer = setTimeout(() => {
        btn.classList.remove("copied");
        if (label) label.textContent = "Copy";
      }, 1800);
    },
    async copyToClipboard(text) {
      try {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(text);
          return true;
        }
      } catch (err) {
        console.warn("navigator.clipboard failed, falling back:", err);
      }
      try {
        const ta = document.createElement("textarea");
        ta.value = text;
        ta.setAttribute("readonly", "");
        ta.style.position = "fixed";
        ta.style.top = "-1000px";
        ta.style.left = "0";
        ta.style.opacity = "0";
        document.body.appendChild(ta);
        ta.select();
        const ok = document.execCommand("copy");
        document.body.removeChild(ta);
        return ok;
      } catch (err) {
        console.warn("Copy fallback failed:", err);
        return false;
      }
    },
    async loadMarkdownPage(key, path) {
      try {
        const response = await fetch(path, { cache: "no-cache" });
        if (!response.ok) {
          throw new Error(`HTTP ${response.status} when loading ${path}`);
        }
        this.documentation[key] = await response.text();
      } catch (error) {
        console.error(`Failed to load markdown for "${key}" from ${path}:`, error);
        this.documentation[key] = `# Page unavailable\n\nCould not load \`${path}\`. Please ensure the docs are served over HTTP (not opened via \`file://\`) and that the file exists.`;
      }
    },
    async loadDocumentation() {
      await this.loadMarkdownPage("getting-started", "./content/getting-started.md");
      await this.loadMarkdownPage("installation", "./content/installation.md");
      await this.loadMarkdownPage("configuration", "./content/configuration.md");
      await this.loadMarkdownPage("publishing", "./content/publishing.md");
      await this.loadMarkdownPage('consuming', "./content/consuming.md");
      await this.loadMarkdownPage('rpc', "./content/rpc.md");
      await this.loadMarkdownPage('queue-management', "./content/queue-management.md");
      await this.loadMarkdownPage('management-api', "./content/management-api.md");
      await this.loadMarkdownPage('message-properties', "./content/message-properties.md");
      await this.loadMarkdownPage('advanced', "./content/advanced.md");
      await this.loadMarkdownPage('best-practices', "./content/best-practices.md");
      await this.loadMarkdownPage('faq', "./content/faq.md");
      await this.loadMarkdownPage('troubleshooting', "./content/troubleshooting.md");
      await this.loadMarkdownPage('queue-driver', "./content/queue-driver.md");
      await this.loadMarkdownPage('artisan-commands', "./content/artisan-commands.md");
      await this.loadMarkdownPage('guide', "./content/guide.md");
    },
  },
}).mount("#app");
