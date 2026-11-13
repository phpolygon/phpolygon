// Procedural eye for the surface hook (PHPolygon\Rendering\EyeShading). Pure functions: every input is an
// argument. The eye mesh is a sphere around its local origin with the pupil axis along local +Z.

float eye_hash(vec2 p) {
    vec3 q = fract(vec3(p.xyx) * 0.1031);
    q += dot(q, q.yzx + 33.33);
    return fract((q.x + q.y) * q.z);
}

float eye_noise(vec2 p) {
    vec2 i = floor(p);
    vec2 f = fract(p);
    f = f * f * (3.0 - 2.0 * f);
    return mix(mix(eye_hash(i), eye_hash(i + vec2(1.0, 0.0)), f.x),
               mix(eye_hash(i + vec2(0.0, 1.0)), eye_hash(i + vec2(1.0, 1.0)), f.x), f.y);
}

// The view direction (from the surface toward the camera) in the mesh's local space, without a model matrix:
// the screen derivatives of the world and of the local position give the surface's tangent frame in both
// spaces, which is the rotation (and uniform scale) between them. Call it where the derivatives are defined
// (control flow uniform over the primitive, before any discard).
vec3 eye_localView(vec3 worldPos, vec3 cameraPos, vec3 localPos) {
    vec3 wx = dFdx(worldPos);
    vec3 wy = dFdy(worldPos);
    vec3 lx = dFdx(localPos);
    vec3 ly = dFdy(localPos);
    vec3 wn = cross(wx, wy);
    vec3 ln = cross(lx, ly);
    float ws = max(length(wx), 1e-8);
    float ls = max(length(lx), 1e-8);
    mat3 W = mat3(wx, wy, normalize(wn + vec3(1e-12)) * ws);
    mat3 L = mat3(lx, ly, normalize(ln + vec3(1e-12)) * ls);
    vec3 v = normalize(cameraPos - worldPos);
    vec3 local = L * (inverse(W) * v);

    return normalize(local + vec3(1e-9));
}

struct EyeSurface {
    vec3 albedo;
    float roughness;
    vec3 emission;
    float iris;       // 1 on the iris and pupil (the cornea over it), 0 on the sclera
};

// irisColor: the iris' base colour (linear). p: x = angular radius of the iris on the eyeball (radians; about 0.5),
// y = pupil radius as a share of the iris radius (0.2 bright light .. 0.55 dark), z = depth of the iris plane behind the
// corneal apex in eyeball radii (about 0.18), w = width of the dark limbal ring (share of the iris radius, about 0.12).
// seed varies the fibres between eyes.
EyeSurface eye_surface(vec3 localPos, vec3 worldPos, vec3 cameraPos, vec3 irisColor, vec4 p, float seed) {
    EyeSurface e;
    vec3 dir = normalize(localPos);
    float irisAngle = p.x;
    float theta = acos(clamp(dir.z, -1.0, 1.0));
    vec3 vl = eye_localView(worldPos, cameraPos, localPos);

    // Light enters through the cornea (index 1.336) and reaches the iris plane behind it: find where the refracted
    // view ray hits that plane. This is what gives the iris its depth: it shifts with the view angle.
    float zPlane = cos(irisAngle) - p.z;
    vec3 rd = refract(-vl, dir, 1.0 / 1.336);
    float t = rd.z < -1e-4 ? (zPlane - dir.z) / rd.z : 0.0;
    vec3 hit = dir + rd * max(t, 0.0);
    float r = length(hit.xy) / max(sin(irisAngle), 1e-3);
    float phi = atan(hit.y, hit.x);

    float inCornea = 1.0 - smoothstep(irisAngle * 1.1, irisAngle * 1.3, theta);
    float irisMask = (1.0 - smoothstep(0.96, 1.0, r)) * inCornea;
    float pupilR = clamp(p.y, 0.1, 0.7);
    float pupil = 1.0 - smoothstep(pupilR - 0.04, pupilR + 0.04, r);

    // Iris: radial fibres (noise along the angle, stretched along the radius), a lighter collarette round the
    // pupil, a darker outer rim, a dark limbal ring.
    float fib = eye_noise(vec2(phi * 9.0 + seed * 7.0, r * 2.5)) * 0.6 + eye_noise(vec2(phi * 23.0 + seed, r * 6.0)) * 0.4;
    vec3 iris = irisColor * (0.55 + 0.9 * fib);
    float collarette = exp(-pow((r - (pupilR + 0.22)) / 0.08, 2.0));
    iris = mix(iris, irisColor * 1.5 + vec3(0.12, 0.08, 0.02), collarette * 0.45);
    float limbal = smoothstep(1.0 - p.w * 2.2, 1.0, r);
    iris *= 1.0 - 0.75 * limbal;

    // Sclera: off-white, a little warmer and veined toward the corners, darker toward the iris.
    float vein = eye_noise(localPos.xy * 9.0 + seed) * eye_noise(localPos.xy * 21.0);
    vec3 sclera = vec3(0.80, 0.78, 0.74) * (1.0 - 0.10 * smoothstep(0.1, 0.5, theta));
    sclera = mix(sclera, vec3(0.78, 0.45, 0.42), 0.35 * smoothstep(0.55, 0.9, vein) * smoothstep(0.8, 1.4, theta));
    sclera *= 1.0 - 0.45 * smoothstep(irisAngle * 1.9, irisAngle * 1.25, theta) * (1.0 - irisMask);

    vec3 base = mix(sclera, iris, irisMask);
    base = mix(base, vec3(0.008), pupil * irisMask);
    e.albedo = base;
    // The cornea is wet and smooth (a small sharp highlight, not a broad sheen); the sclera is semi-rough.
    e.roughness = mix(0.30, 0.035, smoothstep(irisAngle * 1.45, irisAngle * 1.1, theta));
    // A little light of its own so the iris is never dead black (translucent stroma).
    e.emission = iris * 0.035 * irisMask * (1.0 - pupil);
    e.iris = irisMask;

    return e;
}
