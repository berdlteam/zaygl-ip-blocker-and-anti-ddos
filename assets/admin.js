(function () {
  "use strict";

  const ajaxUrl = window.ZAYGL_IPG_AJAX?.ajaxUrl || "";
  const nonce = window.ZAYGL_IPG_AJAX?.nonce || "";
  let ipgLastCheckedRow = null;
  if (!ajaxUrl || !nonce) {
    console.error("[IPG] Missing localized AJAX data.");
    return;
  }

  function qs(selector, root = document) {
    return root.querySelector(selector);
  }

  function qsa(selector, root = document) {
    return Array.from(root.querySelectorAll(selector));
  }

  function isTopTableKey(key) {
    return (
      key === "top5m" ||
      key === "top1h" ||
      key === "top24" ||
      key === "top3d" ||
      key === "top7d"
    );
  }

  function debounce(fn, wait) {
    let timer = null;
    return (...args) => {
      window.clearTimeout(timer);
      timer = window.setTimeout(() => fn(...args), wait);
    };
  }

  function buildBody(data) {
    const params = new URLSearchParams();

    Object.keys(data).forEach((key) => {
      const value = data[key];

      if (Array.isArray(value)) {
        value.forEach((item) => params.append(`${key}[]`, item));
      } else if (value !== undefined && value !== null) {
        params.append(key, String(value));
      }
    });

    return params.toString();
  }

  async function postAction(action, payload = {}) {
    const response = await fetch(ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
      },
      body: buildBody({
        action,
        nonce,
        ...payload,
      }),
    });

    let json = null;

    try {
      json = await response.json();
    } catch (error) {
      throw new Error("Invalid server response.");
    }

    if (!response.ok || !json || json.success !== true) {
      throw new Error(json?.data?.message || "Request failed.");
    }

    return json.data || {};
  }

  function showMessage(el, text, type = "success") {
    if (!el) return;

    el.textContent = text;
    el.classList.remove("is-success", "is-error", "is-active");
    el.classList.add(type === "error" ? "is-error" : "is-success");
    el.classList.add("is-active");

    window.clearTimeout(el._ipgTimer);
    el._ipgTimer = window.setTimeout(() => {
      el.classList.remove("is-active");
    }, 1800);
  }

  function setTopLoading(isLoading) {
    const loading = qs("#ipg-top-loading");
    const range = qs("#ipg_top_range");

    if (loading) {
      loading.classList.toggle("is-active", isLoading);
      loading.classList.toggle("is-blinking", isLoading);
    }

    if (range) {
      range.disabled = isLoading;
    }
  }

  function setRefreshState(wrap, isLoading) {
    if (!wrap) return;
    const msg = qs(".ipg-refresh-msg", wrap);
    if (!msg) return;
    msg.classList.toggle("is-active", isLoading);
  }

  function getTopContainer() {
    return qs("#ipg-top-container");
  }

  function getWrap(tableKey) {
    return qs(`.ipg-table-wrap[data-ipg-table="${tableKey}"]`);
  }

  function currentTopRangeKey() {
    const range = qs("#ipg_top_range");
    const value = range ? range.value : "top24";
    return isTopTableKey(value) ? value : "top24";
  }

  function getTopSearchInput() {
    return qs(".ipg-ip-search");
  }

  function getTopSearchValue() {
    const input = getTopSearchInput();
    return input ? input.value.trim() : "";
  }

  function currentRecentHours() {
    const select = qs("#recent_hours");
    if (!select) return 24;
    const value = parseInt(select.value || "24", 10);
    return Number.isNaN(value) ? 24 : value;
  }

  function recentMinutesFromHours(hours) {
    return hours === 5 ? 5 : 0;
  }

  function recentHoursForAjax(hours) {
    return hours === 5 ? 0 : hours;
  }

  function selectedIps(root = document) {
    return qsa(".ipg-row-select:checked", root)
      .map((el) => (el.value || "").trim())
      .filter(Boolean);
  }

  function exportUrl(action, tableKey) {
    const wrap = getWrap(tableKey);
    if (!wrap) return "";

    const params = new URLSearchParams({
      action,
      nonce,
      table: tableKey,
      orderby: wrap.dataset.orderby || "hits",
      order: wrap.dataset.order || "DESC",
      country: wrap.dataset.country || "",
    });

    return `${ajaxUrl}?${params.toString()}`;
  }

  async function loadTopRange(tableKey, overrides = {}) {
    const container = getTopContainer();
    if (!container) return;

    const country =
      overrides.country !== undefined
        ? overrides.country
        : container.dataset.country || "";

    const ipSearch =
      overrides.ip_search !== undefined
        ? overrides.ip_search
        : container.dataset.ipSearch || getTopSearchValue();

    const page = overrides.page !== undefined ? overrides.page : 1;
    const perPage = overrides.per_page !== undefined ? overrides.per_page : 50;
    const orderby = overrides.orderby !== undefined ? overrides.orderby : "hits";
    const order = overrides.order !== undefined ? overrides.order : "DESC";

    const input = getTopSearchInput();
    if (input) {
      input.value = ipSearch;
    }

    container.dataset.country = country || "";
    container.dataset.ipSearch = ipSearch || "";

    setTopLoading(true);

    try {
      const data = await postAction("zaygl_ipg_table", {
        table: tableKey,
        page,
        per_page: perPage,
        orderby,
        order,
        country,
        ip_search: ipSearch,
      });

      container.innerHTML = data.html || "";

      const filter = qs(`.ipg-country-filter[data-table="${tableKey}"]`, container);
      if (filter) {
        filter.value = country || "";
      }
    } catch (error) {
      alert(error.message);
    } finally {
      setTopLoading(false);
    }
  }

  async function loadTable(tableKey, overrides = {}) {
    const wrap = getWrap(tableKey);
    if (!wrap) return;

    const state = {
      table: tableKey,
      page:
        overrides.page !== undefined
          ? overrides.page
          : parseInt(wrap.dataset.page || "1", 10),
      per_page:
        overrides.per_page !== undefined
          ? overrides.per_page
          : parseInt(wrap.dataset.perPage || "50", 10),
      orderby:
        overrides.orderby !== undefined
          ? overrides.orderby
          : wrap.dataset.orderby || "",
      order:
        overrides.order !== undefined
          ? overrides.order
          : wrap.dataset.order || "DESC",
      country:
        overrides.country !== undefined
          ? overrides.country
          : wrap.dataset.country || "",
      ip_search:
        overrides.ip_search !== undefined
          ? overrides.ip_search
          : wrap.dataset.ipSearch || "",
      recent_hours:
        overrides.recent_hours !== undefined
          ? overrides.recent_hours
          : parseInt(wrap.dataset.recentHours || "24", 10),
      recent_minutes:
        overrides.recent_minutes !== undefined
          ? overrides.recent_minutes
          : 0,
    };

    setRefreshState(wrap, true);

    try {
      const data = await postAction("zaygl_ipg_table", {
        table: state.table,
        page: state.page,
        per_page: state.per_page,
        orderby: state.orderby,
        order: state.order,
        country: state.country,
        ip_search: state.ip_search,
        ...(state.table === "recent"
          ? {
            recent_hours: state.recent_hours,
            recent_minutes: state.recent_minutes,
          }
          : {}),
      });

      wrap.outerHTML = data.html || "";
    } catch (error) {
      alert(error.message);
    } finally {
      const newWrap = getWrap(tableKey);
      setRefreshState(newWrap || wrap, false);
    }
  }

  async function loadRecentTable(overrides = {}) {
    const hours =
      overrides.recent_hours !== undefined
        ? overrides.recent_hours
        : currentRecentHours();

    await loadTable("recent", {
      page: overrides.page !== undefined ? overrides.page : 1,
      per_page: overrides.per_page,
      order: overrides.order,
      recent_hours: recentHoursForAjax(hours),
      recent_minutes: recentMinutesFromHours(hours),
    });
  }

  async function saveNote(rowEl) {
    if (!rowEl) return;

    const row = rowEl.dataset.noteRow;
    const ipInput = qs(".ipg-note-ip", rowEl);
    const commentInput = qs(".ipg-note-comment", rowEl);
    const msg = qs(".ipg-note-msg", rowEl);
    const button = qs(".ipg-note-save", rowEl);

    const ip = ipInput ? ipInput.value.trim() : "";
    const comment = commentInput ? commentInput.value.trim() : "";

    if (button) button.disabled = true;

    try {
      await postAction("zaygl_ipg_notes_save", {
        row,
        ip,
        comment,
      });

      showMessage(msg, "Saved.", "success");
    } catch (error) {
      showMessage(msg, error.message, "error");
    } finally {
      if (button) button.disabled = false;
    }
  }

  async function deleteNote(rowEl) {
    if (!rowEl) return;

    const row = rowEl.dataset.noteRow;
    const msg = qs(".ipg-note-msg", rowEl);
    const button = qs(".ipg-note-delete", rowEl);

    if (button) button.disabled = true;

    try {
      await postAction("zaygl_ipg_notes_delete", { row });

      const ipInput = qs(".ipg-note-ip", rowEl);
      const commentInput = qs(".ipg-note-comment", rowEl);

      if (ipInput) ipInput.value = "";
      if (commentInput) commentInput.value = "";

      showMessage(msg, "Deleted.", "success");
    } catch (error) {
      showMessage(msg, error.message, "error");
    } finally {
      if (button) button.disabled = false;
    }
  }

  async function handleBulkAction() {
    const bulkSelect = qs("#ipg-bulk-action");
    const applyBtn = qs("#ipg-bulk-apply");

    if (!bulkSelect || !applyBtn) return;

    const bulkAction = bulkSelect.value;
    if (!bulkAction) {
      alert("Please choose a bulk action.");
      return;
    }

    const ips = bulkAction === "unblock_all" ? [] : selectedIps(document);

    if (bulkAction !== "unblock_all" && ips.length === 0) {
      alert("Please select at least one IP.");
      return;
    }

    applyBtn.disabled = true;

    try {
      const data = await postAction("zaygl_ipg_bulk_action", {
        bulk_action: bulkAction,
        ips,
      });

      // alert(data.message || "Done.");

      const container = getTopContainer();
      const country = container ? container.dataset.country || "" : "";

      await loadTopRange(currentTopRangeKey(), {
        country,
        ip_search: getTopSearchValue(),
        page: 1,
      });
    } catch (error) {
      alert(error.message);
    } finally {
      applyBtn.disabled = false;
    }
  }

  const onSearch = debounce((input) => {
    const value = (input.value || "").trim();
    const container = getTopContainer();

    if (container) {
      container.dataset.ipSearch = value;
    }

    loadTopRange(currentTopRangeKey(), {
      ip_search: value,
      page: 1,
    });
  }, 350);

  document.addEventListener("input", function (event) {
    const searchInput = event.target.closest(".ipg-ip-search");
    if (searchInput) {
      onSearch(searchInput);
    }
  });

  document.addEventListener("click", async function (event) {
    const saveBtn = event.target.closest(".ipg-note-save");
    if (saveBtn) {
      event.preventDefault();
      await saveNote(saveBtn.closest("tr"));
      return;
    }

    const deleteBtn = event.target.closest(".ipg-note-delete");
    if (deleteBtn) {
      event.preventDefault();
      await deleteNote(deleteBtn.closest("tr"));
      return;
    }

    const refreshBtn = event.target.closest(".ipg-refresh");
    if (refreshBtn) {
      event.preventDefault();

      const table = refreshBtn.dataset.table || "";

      if (isTopTableKey(table)) {
        const container = getTopContainer();
        const country = container ? container.dataset.country || "" : "";

        await loadTopRange(currentTopRangeKey(), {
          country,
          ip_search: getTopSearchValue(),
        });
      } else {
        await loadTable(table);
      }
      return;
    }

    const selectAll = event.target.closest(".ipg-select-all-page");
    if (selectAll) {
      const wrap = selectAll.closest(".ipg-table-wrap");
      if (!wrap) return;

      qsa(".ipg-row-select", wrap).forEach((checkbox) => {
        checkbox.checked = selectAll.checked;
      });
      return;
    }

    const bulkApply = event.target.closest("#ipg-bulk-apply");
    if (bulkApply) {
      event.preventDefault();
      await handleBulkAction();
      return;
    }

    const exportCsv = event.target.closest(".ipg-export-csv");
    if (exportCsv) {
      event.preventDefault();

      const table = currentTopRangeKey();
      const url = exportUrl("zaygl_ipg_export_csv", table);

      if (url) {
        window.location.href = url;
      }
      return;
    }

    const exportPdf = event.target.closest(".ipg-export-pdf");
    if (exportPdf) {
      event.preventDefault();

      const table = currentTopRangeKey();
      const url = exportUrl("zaygl_ipg_export_print", table);

      if (url) {
        window.open(url, "_blank", "noopener,noreferrer");
      }
      return;
    }

    const sort = event.target.closest(".ipg-sort");
    if (sort) {
      event.preventDefault();

      const table = sort.dataset.table || "";
      const orderby = sort.dataset.orderby || "";
      const order = sort.dataset.order || "DESC";

      if (isTopTableKey(table)) {
        const container = getTopContainer();
        const country = container ? container.dataset.country || "" : "";

        await loadTopRange(currentTopRangeKey(), {
          page: 1,
          orderby,
          order,
          country,
          ip_search: getTopSearchValue(),
        });
      } else {
        await loadTable(table, {
          page: 1,
          orderby,
          order,
        });
      }
      return;
    }

    const pageBtn = event.target.closest(".ipg-page");
    if (pageBtn) {
      event.preventDefault();

      if (
        pageBtn.classList.contains("disabled") ||
        pageBtn.getAttribute("aria-disabled") === "true"
      ) {
        return;
      }

      const table = pageBtn.dataset.table || "";
      const page = parseInt(pageBtn.dataset.page || "1", 10);

      if (isTopTableKey(table)) {
        const container = getTopContainer();
        const country = container ? container.dataset.country || "" : "";

        await loadTopRange(currentTopRangeKey(), {
          page,
          country,
          ip_search: getTopSearchValue(),
        });
      } else {
        await loadTable(table, { page });
      }
    }
  });

document.addEventListener("click", function (event) {
  const rowBox = event.target.closest(".ipg-row-select");
  if (!rowBox) return;

  const wrap = rowBox.closest(".ipg-table-wrap");
  if (!wrap) return;

  const boxes = [...wrap.querySelectorAll(".ipg-row-select")];

  if (event.shiftKey && ipgLastCheckedRow && boxes.includes(ipgLastCheckedRow)) {
    const start = boxes.indexOf(ipgLastCheckedRow);
    const end = boxes.indexOf(rowBox);
    const from = Math.min(start, end);
    const to = Math.max(start, end);

    for (let i = from; i <= to; i++) {
      boxes[i].checked = rowBox.checked;
    }
  }

  ipgLastCheckedRow = rowBox;

  const all = wrap.querySelector(".ipg-select-all-page");
  if (all) {
    all.checked = boxes.length > 0 && boxes.every((b) => b.checked);
  }
});
  document.addEventListener("keydown", function (event) {
    if (event.key !== "Enter") return;

    const noteInput = event.target.closest(".ipg-note-ip, .ipg-note-comment");
    if (!noteInput) return;

    event.preventDefault();
    saveNote(noteInput.closest("tr"));
  });
  document.addEventListener("change", async function (event) {
  const rangeSelect = event.target.closest("#ipg_top_range");
  if (rangeSelect) {
    const value = rangeSelect.value;

    if (isTopTableKey(value)) {
      const container = getTopContainer();
      const country = container ? container.dataset.country || "" : "";

      await loadTopRange(value, {
        page: 1,
        country,
        ip_search: getTopSearchValue(),
      });
    }

    return;
  }

  const perPageSelect = event.target.closest(".ipg-per-page");
  if (perPageSelect) {
    const table = perPageSelect.dataset.table || "";
    const perPage = parseInt(perPageSelect.value || "50", 10);

    if (isTopTableKey(table)) {
      const container = getTopContainer();
      const country = container ? container.dataset.country || "" : "";

      await loadTopRange(currentTopRangeKey(), {
        per_page: perPage,
        page: 1,
        country,
        ip_search: getTopSearchValue(),
      });
    } else if (table === "recent") {
      await loadRecentTable({
        per_page: perPage,
        page: 1,
      });
    } else {
      await loadTable(table, {
        per_page: perPage,
        page: 1,
      });
    }

    return;
  }

  const countrySelect = event.target.closest(".ipg-country-filter");
  if (countrySelect) {
    const table = countrySelect.dataset.table || "";
    const country = (countrySelect.value || "").toUpperCase();

    if (isTopTableKey(table)) {
      const container = getTopContainer();
      if (container) {
        container.dataset.country = country;
      }

      await loadTopRange(currentTopRangeKey(), {
        country,
        ip_search: getTopSearchValue(),
        page: 1,
      });
    } else {
      await loadTable(table, {
        country,
        page: 1,
      });
    }
  }
});

  document.addEventListener("DOMContentLoaded", function () {
    const container = getTopContainer();
    if (container) {
      loadTopRange(currentTopRangeKey(), {
        country: container.dataset.country || "",
        ip_search: container.dataset.ipSearch || getTopSearchValue(),
      });
    }
  });
})();