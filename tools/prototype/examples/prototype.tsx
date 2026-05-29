// Example react-three-fiber prototype - the kind of file Claude Desktop might
// produce quickly. Import it into PHPolygon with:
//
//   node scripts/r3f-import.mjs examples/prototype.tsx --out prototype.import.json
//   php bin/phpolygon scene:import prototype.import.json --out src/Scene/Prototype.php
//
// mesh/group + primitive geometry/material import cleanly; the <primitive> and
// the lights below show up as warnings (not silently dropped).
import { Canvas } from '@react-three/fiber'

export default function Prototype() {
  return (
    <Canvas>
      <ambientLight intensity={0.5} />
      <directionalLight position={[6, 12, 8]} intensity={1.2} color="#fff5e0" />
      <pointLight name="Lamp" position={[3, 4, 0]} intensity={2.0} distance={15} color="#88ccff" />

      <mesh name="Ground" position={[0, 0, 0]} scale={[40, 1, 40]}>
        <planeGeometry args={[1, 1]} />
        <meshStandardMaterial color="#3a3a44" roughness={0.9} />
      </mesh>

      <group name="District" position={[0, 0, 0]}>
        <mesh name="Building" position={[10, 0, 5]} rotation={[0, 0.4, 0]}>
          <boxGeometry args={[6, 12, 5]} />
          <meshStandardMaterial color="#9a5b3a" roughness={0.8} metalness={0.1} />
        </mesh>
        <mesh name="Orb" position={[3, 1.5, 0]}>
          <sphereGeometry args={[1, 32, 16]} />
          <meshStandardMaterial color="#cccccc" roughness={0.3} metalness={0.6} />
        </mesh>
      </group>
    </Canvas>
  )
}
