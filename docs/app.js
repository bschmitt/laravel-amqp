const { createApp } = Vue;

createApp({
  data() {
    return {
      currentPage: "home",
      activeTab: "install",
      searchQuery: "",
      isDark: false,
      mobileMenuOpen: false,
      documentation: {},
      searchOpen: false,
      searchResults: [],
      selectedResultIndex: 0,
      contributors: [],
      loadingContributors: true,
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
    currentPage() {
      // Scroll to top when page changes
      window.scrollTo({ top: 0, behavior: "smooth" });

      // Re-highlight code blocks
      this.$nextTick(() => {
        Prism.highlightAll();
      });
    },
    activeTab() {
      this.$nextTick(() => {
        Prism.highlightAll();
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

      if (window.location.hash) {
        const page = window.location.hash.substring(1);
        if (page && page !== "home" && this.documentation[page]) {
          this.currentPage = page;
        }
      }

      document.addEventListener("keydown", this.handleKeyboard);

      this.fetchContributors();
    } catch (error) {
      console.error("Error in mounted hook:", error);
      this.fetchContributors();
    }
  },
  beforeUnmount() {
    document.removeEventListener("keydown", this.handleKeyboard);
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
      this.currentPage = result.page;
      this.closeSearch();
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
      await this.loadMarkdownPage('guide', "./content/guide.md");
    },
  },
}).mount("#app");
