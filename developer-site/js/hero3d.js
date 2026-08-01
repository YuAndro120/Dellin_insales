/* Герой: 3D-граф «интеграций» — узлы-сервисы, связанные линиями.
   Чернильные рёбра + охристые узлы на прозрачном фоне.
   Уважает prefers-reduced-motion (статичный кадр) и вкладку в фоне. */
(function () {
  'use strict';

  const canvas = document.getElementById('hero3d');
  if (!canvas || typeof THREE === 'undefined') return;

  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const renderer = new THREE.WebGLRenderer({ canvas: canvas, alpha: true, antialias: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));

  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(42, 1, 0.1, 100);
  camera.position.z = 8;

  const group = new THREE.Group();
  scene.add(group);

  const NODES = 26;
  const positions = [];
  for (let i = 0; i < NODES; i++) {
    const r = 2.1 + Math.random() * 1.0;
    const theta = Math.random() * Math.PI * 2;
    const phi = Math.acos(2 * Math.random() - 1);
    positions.push(new THREE.Vector3(
      r * Math.sin(phi) * Math.cos(theta),
      r * Math.sin(phi) * Math.sin(theta),
      r * Math.cos(phi)
    ));
  }

  const edgeSet = new Set();
  positions.forEach(function (p, i) {
    positions
      .map(function (q, j) { return { j: j, d: p.distanceTo(q) }; })
      .filter(function (o) { return o.j !== i; })
      .sort(function (a, b) { return a.d - b.d; })
      .slice(0, 2)
      .forEach(function (o) {
        edgeSet.add(Math.min(i, o.j) + '-' + Math.max(i, o.j));
      });
  });

  const linePoints = [];
  edgeSet.forEach(function (key) {
    const pair = key.split('-');
    linePoints.push(positions[+pair[0]], positions[+pair[1]]);
  });

  const lineGeo = new THREE.BufferGeometry().setFromPoints(linePoints);
  const lineMat = new THREE.LineBasicMaterial({
    color: 0x1B1813, transparent: true, opacity: 0.28
  });
  group.add(new THREE.LineSegments(lineGeo, lineMat));

  const ochre = new THREE.MeshBasicMaterial({ color: 0xB4791B });
  const ink = new THREE.MeshBasicMaterial({ color: 0x1B1813 });
  positions.forEach(function (p, i) {
    const big = i % 5 === 0;
    const node = new THREE.Mesh(
      new THREE.SphereGeometry(big ? 0.085 : 0.05, 12, 12),
      big ? ochre : ink
    );
    node.position.copy(p);
    group.add(node);
  });

  function resize() {
    const w = canvas.clientWidth || 380;
    const h = canvas.clientHeight || 380;
    renderer.setSize(w, h, false);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
  }
  resize();
  window.addEventListener('resize', function () { resize(); if (reduced) renderer.render(scene, camera); });

  let targetX = 0, targetY = 0;
  if (!reduced) {
    window.addEventListener('pointermove', function (e) {
      targetX = (e.clientX / window.innerWidth - 0.5) * 0.5;
      targetY = (e.clientY / window.innerHeight - 0.5) * 0.35;
    }, { passive: true });
  }

  let raf = null;
  function animate() {
    group.rotation.y += 0.0018;
    group.rotation.x += (targetY - group.rotation.x) * 0.03;
    group.rotation.z += (targetX * 0.2 - group.rotation.z) * 0.03;
    renderer.render(scene, camera);
    raf = requestAnimationFrame(animate);
  }

  if (reduced) {
    group.rotation.set(0.35, 0.6, 0);
    renderer.render(scene, camera);
  } else {
    animate();
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        if (raf) cancelAnimationFrame(raf);
        raf = null;
      } else if (!raf) {
        animate();
      }
    });
  }
})();
