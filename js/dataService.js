(function (global) {
  const cache = Object.create(null);
  const inflight = Object.create(null);

  function clone(data) {
    if (typeof structuredClone === 'function') {
      try {
        return structuredClone(data);
      } catch (err) {
        // fall through to JSON clone
      }
    }
    return data == null ? data : JSON.parse(JSON.stringify(data));
  }

  function fetchResource(key, url, options = {}) {
    const { force = false } = options;

    if (!force && Object.prototype.hasOwnProperty.call(cache, key)) {
      return Promise.resolve(clone(cache[key]));
    }

    if (inflight[key]) {
      return inflight[key].then(clone);
    }

    inflight[key] = fetch(url, { cache: 'no-store' })
      .then(response => {
        if (!response.ok) {
          throw new Error(`Request failed: ${response.status} ${response.statusText}`);
        }
        return response.json();
      })
      .then(data => {
        cache[key] = data;
        return data;
      })
      .finally(() => {
        delete inflight[key];
      });

    return inflight[key].then(clone);
  }

  function invalidate(keys) {
    ([]).concat(keys).forEach(key => {
      delete cache[key];
    });
  }

  const DataService = {
    fetchCategories(options) {
      return fetchResource('categoriesAll', 'db/categories_getAll.php', options);
    },
    fetchActiveCategories(options) {
      return fetchResource('categoriesActive', 'db/categories_get.php', options);
    },
    fetchSizes(options) {
      return fetchResource('sizesAll', 'db/sizes_getAll.php', options);
    },
    fetchActiveSizes(options) {
      return fetchResource('sizesActive', 'db/sizes_get.php', options);
    },
    fetchUnits(options) {
      return fetchResource('unitsAll', 'db/product_units_get.php', options);
    },
    invalidateCategories() {
      invalidate(['categoriesAll', 'categoriesActive']);
    },
    invalidateSizes() {
      invalidate(['sizesAll', 'sizesActive']);
    },
    invalidateUnits() {
      invalidate(['unitsAll']);
    },
    invalidateAll() {
      Object.keys(cache).forEach(key => delete cache[key]);
    }
  };

  global.DataService = DataService;
})(window);

